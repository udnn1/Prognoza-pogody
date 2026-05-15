<?php
declare(strict_types=1);
const RSS_URL='https://pogodadlaslaska.pl/blog/prognoza-krotkoterminowa/rss';
const APP_TITLE='Pogoda dla Śląska - 3 dniowa';
const TELEGRAM_STATE_FILE=__DIR__.'/telegram_weather_state.json';

function envValue($name,$default=''){
    $v=getenv($name);
    return $v===false?$default:$v;
}

function hasCliFlag($flag){
    global $argv;
    return PHP_SAPI==='cli'&&in_array($flag,$argv??[],true);
}

function isTelegramNotifyMode(){return hasCliFlag('--telegram');}
function isTelegramForceMode(){return hasCliFlag('--force');}

function escape($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function toLowercase($t){return function_exists('mb_strtolower')?mb_strtolower((string)$t,'UTF-8'):strtolower((string)$t);}
function toUppercase($t){return function_exists('mb_strtoupper')?mb_strtoupper((string)$t,'UTF-8'):strtoupper((string)$t);}
function safePregReplace($p,$r,$s,$l=-1){$x=preg_replace($p,$r,$s,$l);return$x===null?$s:$x;}
function containsAnyKeyword($text,$keywords){foreach($keywords as$k)if(strpos((string)$text,(string)$k)!==false)return true;return false;}

function capitalizeFirstVisibleLetterInHtml($html){
    $html=(string)$html;if(trim($html)==='')return$html;$changed=false;
    $r=preg_replace_callback('/(^\s*(?:<[^>]+>\s*)*[\s\p{P}\p{S}]*)(\p{Ll})/u',function($m)use(&$changed){if($changed)return$m[0];$changed=true;return$m[1].toUppercase($m[2]);},$html,1);
    return is_string($r)?$r:$html;
}

function fetchWeatherFeed($url){
    if(!function_exists('curl_init'))return['body'=>null,'error'=>'Na serwerze nie jest dostępne rozszerzenie cURL.'];
    $ch=curl_init($url);if($ch===false)return['body'=>null,'error'=>'Nie udało się zainicjować połączenia z kanałem RSS.'];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'WeatherReport/2.0 (+https://pogodadlaslaska.pl)',CURLOPT_HTTPHEADER=>['Accept: application/rss+xml, application/xml;q=0.9, */*;q=0.8']]);
    $body=curl_exec($ch);
    if($body===false){$e=curl_error($ch);curl_close($ch);return['body'=>null,'error'=>'Nie udało się pobrać prognozy: '.$e];}
    $key=defined('CURLINFO_RESPONSE_CODE')?CURLINFO_RESPONSE_CODE:CURLINFO_HTTP_CODE;$code=(int)curl_getinfo($ch,$key);curl_close($ch);
    if($code>=400)return['body'=>null,'error'=>'Serwer źródłowy zwrócił błąd HTTP '.$code.'.'];
    return['body'=>is_string($body)?$body:null,'error'=>null];
}

function parseRss($xml){
    if(!function_exists('simplexml_load_string'))return null;
    $flags=LIBXML_NOCDATA;if(defined('LIBXML_NONET'))$flags|=LIBXML_NONET;
    $old=libxml_use_internal_errors(true);$rss=simplexml_load_string($xml,'SimpleXMLElement',$flags);libxml_clear_errors();libxml_use_internal_errors($old);
    return$rss instanceof SimpleXMLElement?$rss:null;
}

function extractItemContent($item){
    $ns=$item->getNameSpaces(true);
    if(isset($ns['content'])){$c=$item->children($ns['content']);if(isset($c->encoded)&&trim((string)$c->encoded)!=='')return(string)$c->encoded;}
    return isset($item->description)?(string)$item->description:'';
}

function normalizeSourceHtml($html){
    $html=str_replace(['&nbsp;',"\xc2\xa0"],' ',(string)$html);$html=safePregReplace('/<img\b[^>]*>/iu','',$html);
    foreach(['/<p\b[^>]*>\s*Zaproś mnie na wirtualną kawę.*?<\/p>/isu','/<p\b[^>]*>\s*Wszelkie materiały przedstawione na stronie.*?<\/p>/isu','/<p\b[^>]*>\s*Prognoza została przygotowana przez.*?<\/p>/isu','/<p\b[^>]*>\s*Czy\s+w\s+kolejnych\s+dniach\s+wróci\s+wyższa\s+temperatura\?.*?<\/p>/isu','/<p\b[^>]*>\s*Zapraszam\s+na\s+najnowszą\s+kilkudniową\s+prognozę\.?\s*<\/p>/isu','/<p\b[^>]*>\s*Wolałbym\s+mieć\s+dla\s+Was\s+cieplejsze\s+prognozy.*?postawienia\s+wirtualnej\s+KAWKI\s*<\/p>/isu']as$p)$html=safePregReplace($p,'',$html);
    $html=safePregReplace('/Jeśli moje prognozy pomagają.*$/isu','',$html);
    $html=safePregReplace('/Wszelkie materiały \(teksty, grafiki, zdjęcia.*$/isu','',$html);
    return trim($html);
}

function removeBoilerplateText($text){
    $text=safePregReplace('/Czy\s+w\s+kolejnych\s+dniach\s+wróci\s+wyższa\s+temperatura\?.*/iu','',(string)$text);
    $text=safePregReplace('/Zapraszam\s+na\s+najnowszą\s+kilkudniową\s+prognozę\.?/iu','',$text);
    $text=safePregReplace('/Wolałbym\s+mieć\s+dla\s+Was\s+cieplejsze\s+prognozy.*?postawienia\s+wirtualnej\s+KAWKI/iu','',$text);
    $text=safePregReplace('/Jeśli\s+moje\s+prognozy\s+pomagają.*$/isu','',$text);
    $text=safePregReplace('/Wszelkie\s+materiały\s+\(teksty,\s+grafiki,\s+zdjęcia.*$/isu','',$text);
    return trim($text);
}

function detectLeadingDayTitle($text){
    $n=trim(toLowercase($text));
    foreach(['/^noc\s+z\s+niedzieli\s+na\s+poniedzia(l|ł)ek\b/u'=>'NOC Z NIEDZIELI NA PONIEDZIAŁEK','/^noc\s+z\s+poniedzia(l|ł)ku\s+na\s+wtorek\b/u'=>'NOC Z PONIEDZIAŁKU NA WTOREK','/^noc\s+z\s+wtorku\s+na\s+środ(ę|e)\b/u'=>'NOC Z WTORKU NA ŚRODĘ','/^noc\s+z\s+środy\s+na\s+czwartek\b/u'=>'NOC ZE ŚRODY NA CZWARTEK','/^noc\s+z\s+czwartku\s+na\s+piątek\b/u'=>'NOC Z CZWARTKU NA PIĄTEK','/^noc\s+z\s+piątku\s+na\s+sobote?\b/u'=>'NOC Z PIĄTKU NA SOBOTĘ','/^noc\s+z\s+soboty\s+na\s+niedzielę\b/u'=>'NOC Z SOBOTY NA NIEDZIELĘ']as$p=>$t)if(preg_match($p,$n)===1)return$t;
    foreach(['poniedziałek'=>'PONIEDZIAŁEK','poniedzialek'=>'PONIEDZIAŁEK','wtorek'=>'WTOREK','środa'=>'ŚRODA','sroda'=>'ŚRODA','czwartek'=>'CZWARTEK','piątek'=>'PIĄTEK','piatek'=>'PIĄTEK','sobota'=>'SOBOTA','niedziela'=>'NIEDZIELA']as$k=>$t)if(preg_match('/^'.preg_quote($k,'/').'\b/u',$n)===1)return$t;
    return null;
}

function canonicalDayTitle($title){$d=detectLeadingDayTitle((string)$title);return$d!==null?toLowercase($d):toLowercase(trim(safePregReplace('/\s*\[[^\]]+\]\s*/u',' ',(string)$title)));}
function hasDayTitle($days,$title){$n=canonicalDayTitle($title);foreach($days as$d)if(isset($d['title'])&&canonicalDayTitle((string)$d['title'])===$n)return true;return false;}

function isNightOnlyForecastText($text){
    $n=toLowercase(safePregReplace('/\s+/u',' ',(string)$text));
    if(containsAnyKeyword($n,['noc z ','w nocy','nocą','nad ranem','przed świtem','przy samym gruncie','przy gruncie','mrozowisk','temperatura minimalna','minimalna temperatura','temp. minimalna']))return true;
    return containsAnyKeyword($n,['temperatura spadnie','temperatura będzie jeszcze niższa','spadnie do','spadną do'])&&containsAnyKeyword($n,['przymrozki','przymrozek','przymrozk','mrozowisk','mróz','mroz','szron','przy samym gruncie','przy gruncie']);
}

function ensureMondayDay($content,$days){
    if(hasDayTitle($days,'PONIEDZIAŁEK'))return$days;
    $txt=html_entity_decode(strip_tags((string)$content));$txt=str_replace(["\r\n","\r"],"\n",$txt);$txt=safePregReplace('/\n{3,}/u',"\n\n",$txt);
    if(preg_match('/\bponiedzia(?:ł|l)ek\b[^\n]{0,420}/iu',$txt,$m,PREG_OFFSET_CAPTURE)!==1)return$days;
    $raw=$m[0][0];$off=(int)$m[0][1];$ctx=substr($txt,max(0,$off-220),strlen($raw)+440);
    if(preg_match('/noc\s+z\s+niedzieli\s+na\s+poniedzia(?:ł|l)ek/iu',$ctx)===1)return$days;
    $s=removeBoilerplateText(safePregReplace('/\s+/u',' ',trim($raw)));if($s===''||isNightOnlyForecastText($s))return$days;
    $day=buildForecastDay('PONIEDZIAŁEK','<p>'.escape($s).'</p>',$s);if($day!==null)array_unshift($days,$day);return$days;
}

function pruneHtmlNode($node){
    $allowed=['br'=>true,'em'=>true,'li'=>true,'ol'=>true,'p'=>true,'strong'=>true,'ul'=>true];$children=[];
    foreach($node->childNodes as$c)$children[]=$c;foreach($children as$c)pruneHtmlNode($c);
    if($node instanceof DOMComment){if($node->parentNode)$node->parentNode->removeChild($node);return;}
    if(!($node instanceof DOMElement))return;
    while($node->attributes->length>0){$a=$node->attributes->item(0);if($a!==null)$node->removeAttributeNode($a);}
    if(strtolower($node->tagName)==='div'||isset($allowed[strtolower($node->tagName)]))return;
    $p=$node->parentNode;if(!($p instanceof DOMNode))return;
    while($node->firstChild!==null)$p->insertBefore($node->firstChild,$node);$p->removeChild($node);
}

function sanitizeForecastHtml($html){
    $html=trim((string)$html);if($html==='')return'';
    $html=safePregReplace('/<script\b[^>]*>.*?<\/script>/isu','',$html);$html=safePregReplace('/<style\b[^>]*>.*?<\/style>/isu','',$html);
    if(!class_exists('DOMDocument')){$f=trim(strip_tags($html));return$f===''?'':'<p>'.nl2br(escape($f)).'</p>';}
    $doc=new DOMDocument('1.0','UTF-8');$flags=0;if(defined('LIBXML_HTML_NOIMPLIED'))$flags|=LIBXML_HTML_NOIMPLIED;if(defined('LIBXML_HTML_NODEFDTD'))$flags|=LIBXML_HTML_NODEFDTD;
    $old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>',$flags);libxml_clear_errors();libxml_use_internal_errors($old);
    $root=$doc->getElementsByTagName('div')->item(0);if(!($root instanceof DOMElement)){$f=trim(strip_tags($html));return$f===''?'':'<p>'.nl2br(escape($f)).'</p>';}
    pruneHtmlNode($root);$out='';$children=[];foreach($root->childNodes as$c)$children[]=$c;foreach($children as$c)$out.=$doc->saveHTML($c);
    $out=safePregReplace('/<p>\s*<\/p>/iu','',$out);$out=safePregReplace('/\n{3,}/',"\n\n",$out);return trim($out);
}

function maxTemperaturePatterns(){return['/\b(?:temp\.?\s*)?max\.?[^\d-]{0,60}(-?\d{1,2})(?:\s*\/\s*(-?\d{1,2}))?(?:\s*°?\s*C|\s*st\.?\s*C?)/iu','/temperatur(?:a|y)?\s+maksymaln(?:a|ej|e)?[^\d-]{0,60}(-?\d{1,2})(?:\s*\/\s*(-?\d{1,2}))?(?:\s*°?\s*C|\s*st\.?\s*C?)/iu','/\bmaks(?:ymalna)?\.?[^\d-]{0,60}(-?\d{1,2})(?:\s*\/\s*(-?\d{1,2}))?(?:\s*°?\s*C|\s*st\.?\s*C?)/iu'];}
function isTemperatureRangeMatch($m){return isset($m[2])&&$m[2]!=='';}
function formatTemperatureMatch($m){return isTemperatureRangeMatch($m)?formatTemperatureValue(((int)$m[1]+(int)$m[2])/2):$m[1].'°C';}
function extractTemperatureSnippet($text){
    $n=safePregReplace('/\s+/u',' ',(string)$text);$l=toLowercase($n);
    $drop=containsAnyKeyword($l,['spadnie','spadek','spadku','niższa','nizsza','ochłodzi','ochłodzenie','zimniej','zmniejszy']);
    $max=containsAnyKeyword($l,['maks','maksymaln','najwyższa','najwyzsza','max','najwyższa temp']);
    if($drop&&!$max)return'';
    foreach(maxTemperaturePatterns()as$p)if(preg_match($p,$n,$m)===1)return formatTemperatureMatch($m);
    if(preg_match('/(?<!\d)(-?\d{1,2})\s*\/\s*(-?\d{1,2})(?:\s*°?\s*C|\s*st\.?\s*C?)?/iu',$n,$m)===1&&!containsAnyKeyword($l,['rano','nad ranem','temp. rano','min','minimaln','w nocy','nocą','minimalna temp','temperatura minimalna']))return formatTemperatureValue(((int)$m[1]+(int)$m[2])/2);
    return'';
}

function formatTemperatureValue($v){$r=round((float)$v,1);return abs($r-round($r))<0.05?(string)((int)round($r)).'°C':str_replace('.',',',number_format($r,1,'.','')).'°C';}
function extractTemperatureBadge($text){
    $n=safePregReplace('/\s+/u',' ',(string)$text);$l=toLowercase($n);
    if(preg_match('/(?<!\d)(-?\d{1,2})\s*\/\s*(-?\d{1,2})(?:\s*°?\s*C|\s*st\.?\s*C?)?/iu',$n,$m)===1&&!containsAnyKeyword($l,['rano','nad ranem','temp. rano','min','minimaln','w nocy','nocą']))return['value'=>formatTemperatureValue(((int)$m[1]+(int)$m[2])/2),'label'=>'śr.'];
    foreach(maxTemperaturePatterns()as$p)if(preg_match($p,$n,$m)===1)return['value'=>formatTemperatureMatch($m),'label'=>isTemperatureRangeMatch($m)?'śr.':'maks.'];
    $v=extractTemperatureSnippet($text);return$v===''?['value'=>'','label'=>'']:['value'=>$v,'label'=>'maks.'];
}

function extractLeadForecastText($text){
    $text=str_replace(["\r\n","\r"],"\n",trim((string)$text));if($text==='')return'';
    foreach(preg_split('/\n\s*\n+/u',$text)?:[$text]as$p){$c=safePregReplace('/\s+/u',' ',trim($p));if($c!=='')return$c;}return'';
}

function extractLeadForecastHtml($html){
    $html=trim((string)$html);if($html==='')return'';
    if(preg_match_all('/<p\b[^>]*>.*?<\/p>/isu',$html,$ps)===1||!empty($ps[0]))foreach($ps[0]as$p)if(trim(strip_tags($p))!=='')return trim($p);
    $t=extractLeadForecastText(html_entity_decode(strip_tags(str_replace(['<br>','<br/>','<br />'],["\n","\n","\n"],$html)),ENT_QUOTES,'UTF-8'));
    return$t===''?'':'<p>'.nl2br(escape($t)).'</p>';
}

function hasExplicitNoRainSignal($text){
    $n=toLowercase($text);
    foreach(['/\bbez\s+opad(?:ó|o)w\b/u','/\bbrak\s+opad(?:ó|o)w\b/u','/\bbez\s+deszczu\b/u','/\bdeszcz(?:u)?\s+nie\s+(?:musimy|trzeba|należy|nalezy|ma\s+co)\s+si(?:ę|e)\s+obawia(?:ć|c)\b/u','/\bnie\s+(?:powinno|powinien)\s+pada(?:ć|c)\b/u']as$p)if(preg_match($p,$n)===1)return true;
    return false;
}

function hasRainSignal($text){$n=toLowercase($text);if(preg_match('/\bbez\s+opad(?:ó|o)w\b/u',$n)===1||preg_match('/\bbrak\s+opad(?:ó|o)w\b/u',$n)===1)return false;return containsAnyKeyword($n,['opady deszczu','deszcz','mżawk','mzawk','ulew']);}
function hasNoPrecipitationSignal($text){$n=toLowercase($text);return preg_match('/\bbez\s+opad(?:ó|o)w\b/u',$n)===1||preg_match('/\bbrak\s+opad(?:ó|o)w\b/u',$n)===1;}
function removeNonLocalForecastContext($text){
    $text=(string)$text;$resume='(?=\b(?:nadal|temperatura|wiatr|ciśnienie|cisnienie)\b|\btemp\.?\s|$)';
    $nonLocal='(?:na\s+wschodzie(?:\s+polski)?|nad\s+morzem|poza\s+regionem|w\s+innych\s+regionach)';
    foreach([
        '/\bjako\s+ciekawostk(?:ę|e)\s+dodam,?\s+(?:że|ze)\b.*?'.$resume.'/isu',
        '/\bw\s+tym\s+samym\s+czasie\b(?=.*?\b'.$nonLocal.'\b).*?'.$resume.'/isu',
        '/\b'.$nonLocal.'\b.*?'.$resume.'/isu'
    ]as$p)$text=safePregReplace($p,' ',$text);
    return trim(safePregReplace('/\s+/u',' ',$text));
}
function hasCloudIncreaseToLargeSignal($text){$n=toLowercase($text);return preg_match('/\bzachmurzenie[^.!?]{0,140}\b(?:wzrośnie|wzrosnie|wzrastające|wzrastajace|wzrastać|wzrastac|zwiększy\s+się|zwiekszy\s+sie)[^.!?]{0,140}\b(?:do\s+)?(?:dużego|duzego|duże|duze|znacznego|większego|wiekszego)\b/u',$n)===1||preg_match('/\b(?:duże|duze|dużego|duzego|znaczne|większe|wieksze)\s+zachmurzenie\b/u',$n)===1;}
function hasClearSkySignal($text){$n=toLowercase($text);return preg_match('/\b(bezchmurn(?:e|ie|ego|ym)|niemal\s+bezchmurn(?:e|ie)|niebo\s+bez\s+chmur)\b/u',$n)===1;}
function hasLimitedCloudIncreaseSignal($text){$n=toLowercase($text);return preg_match('/\bzachmurzenie[^.!?]{0,100}\b(?:wzrośnie|wzrosnie|wzrastające|wzrastajace|wzrastać|wzrastac|zwiększy\s+się|zwiekszy\s+sie)[^.!?]{0,100}\b(?:najwyżej|najwyzej|maksymalnie|tylko)?[^.!?]{0,40}\bdo\s+mał(?:ego|e|ym)\b/u',$n)===1;}
function hasLowCloudSignal($text){$n=toLowercase($text);if(hasCloudIncreaseToLargeSignal($n))return false;return preg_match('/\bmał(?:e|e\s+i\s+umiarkowane)?\s+zachmurzenie\b/u',$n)===1||preg_match('/\bzachmurzenie[^.!?]{0,100}\b(?:początkowo\s+)?mał(?:e|ego|ym)\b/u',$n)===1||hasLimitedCloudIncreaseSignal($n);}

function getSunnyForecastTheme(){return['icon'=>'sun','label'=>'Słonecznie','panel_classes'=>'bg-amber-500 text-white shadow-amber-500/25','chip_classes'=>'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200','surface_classes'=>'border-amber-200/70 bg-amber-50/60','accent_classes'=>'from-amber-400 via-orange-400 to-yellow-300'];}
function hasPositiveSunSignal($text){return containsAnyKeyword(toLowercase($text),['od rana słonecznie','od rana slonecznie','słonecznie od rana','slonecznie od rana','od rana słoneczny','od rana sloneczny','od rana słoneczna','od rana sloneczna','od rana słoneczne','od rana sloneczne','będzie od rana słoneczny','bedzie od rana sloneczny','będzie od rana słoneczna','bedzie od rana sloneczna','będzie od rana słoneczne','bedzie od rana sloneczne','słoneczny','sloneczny','słoneczna','sloneczna','słoneczne','sloneczne','słonecznie','slonecznie','dużo słońca','duzo slonca','sporo słońca','sporo slonca','więcej słońca','wiecej slonca','rozpogodzenia','pogodnie','bezchmurnie','słońce','slonce','słonecz','slonecz','słońc','slonc']);}
function hasStrongMorningSunSignal($text){return containsAnyKeyword(toLowercase($text),['od rana słonecznie','od rana slonecznie','słonecznie od rana','slonecznie od rana','rano słonecznie','rano slonecznie','od rana słoneczny','od rana sloneczny','od rana słoneczna','od rana sloneczna','od rana słoneczne','od rana sloneczne','będzie od rana słoneczny','bedzie od rana sloneczny','będzie od rana słoneczna','bedzie od rana sloneczna','będzie od rana słoneczne','bedzie od rana sloneczne','od rana dużo słońca','od rana duzo slonca','od rana sporo słońca','od rana sporo slonca','od rana pogodnie','pogodnie od rana']);}
function hasLessSunSignal($text){$n=toLowercase($text);if(hasLimitedCloudIncreaseSignal($n))return false;return containsAnyKeyword($n,['mniej słońca','mniej slonca','mniej rozpogodzeń','mniej rozpogodzen','mało słońca','malo slonca','zachmurzenie wzrośnie','zachmurzenie wzrosnie','więcej chmur','wiecej chmur','bez słońca','bez slonca']);}

function getForecastTheme($text){
    $n=toLowercase($text);$local=removeNonLocalForecastContext($n);$no=hasNoPrecipitationSignal($local)||hasExplicitNoRainSignal($local);$clear=hasClearSkySignal($local);$storm=containsAnyKeyword($local,['burz','piorun']);$morning=hasStrongMorningSunSignal($local);$rain=!$no&&hasRainSignal($local);
    if(!$rain&&((hasPositiveSunSignal($local)&&!hasLessSunSignal($local)&&!$storm)||($morning&&!$storm)))return getSunnyForecastTheme();
    $themes=[
        ['keywords'=>['burz','piorun'],'icon'=>'storm','label'=>'Burzowo','panel_classes'=>'bg-indigo-500 text-white shadow-indigo-500/25','chip_classes'=>'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-200','surface_classes'=>'border-indigo-200/70 bg-indigo-50/55','accent_classes'=>'from-indigo-500 via-violet-500 to-sky-500'],
        ['keywords'=>['przymrozk','przymrozek'],'icon'=>'temp-low','label'=>'Przymrozek','panel_classes'=>'bg-cyan-500 text-white shadow-cyan-500/25','chip_classes'=>'bg-cyan-50 text-cyan-700 ring-1 ring-inset ring-cyan-200','surface_classes'=>'border-cyan-200/70 bg-cyan-50/60','accent_classes'=>'from-cyan-500 via-sky-500 to-blue-500'],
        ['keywords'=>['śnieg','snieg','marzną','mróz','mroz','szron'],'icon'=>'snow','label'=>'Śnieg lub mróz','panel_classes'=>'bg-cyan-500 text-white shadow-cyan-500/25','chip_classes'=>'bg-cyan-50 text-cyan-700 ring-1 ring-inset ring-cyan-200','surface_classes'=>'border-cyan-200/70 bg-cyan-50/60','accent_classes'=>'from-cyan-400 via-sky-400 to-slate-400'],
        ['keywords'=>['deszcz','opad','ulew','mżawk','mzawk'],'icon'=>'rain','label'=>'Opady','panel_classes'=>'bg-sky-500 text-white shadow-sky-500/25','chip_classes'=>'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200','surface_classes'=>'border-sky-200/70 bg-sky-50/60','accent_classes'=>'from-sky-500 via-blue-500 to-cyan-500'],
        ['keywords'=>['wiatr','wietrz','wichur'],'icon'=>'wind','label'=>'Wietrznie','panel_classes'=>'bg-teal-500 text-white shadow-teal-500/25','chip_classes'=>'bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-200','surface_classes'=>'border-teal-200/70 bg-teal-50/60','accent_classes'=>'from-teal-500 via-emerald-500 to-cyan-500'],
        ['keywords'=>['mgł','mgl','zamglen'],'icon'=>'fog','label'=>'Mglisto','panel_classes'=>'bg-slate-500 text-white shadow-slate-500/25','chip_classes'=>'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200','surface_classes'=>'border-slate-200/70 bg-slate-50/65','accent_classes'=>'from-slate-500 via-slate-400 to-zinc-400'],
        getSunnyForecastTheme(),
        ['keywords'=>['noc','wieczor','w nocy'],'icon'=>'moon','label'=>'Noc','panel_classes'=>'bg-violet-500 text-white shadow-violet-500/25','chip_classes'=>'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-200','surface_classes'=>'border-violet-200/70 bg-violet-50/60','accent_classes'=>'from-violet-500 via-fuchsia-500 to-indigo-500']
    ];
    foreach($themes as$t){
        if($no&&$t['icon']==='rain')continue;if($clear&&in_array($t['icon'],['rain','storm','fog'],true))continue;
        if($t['icon']==='snow'){foreach(['słońc','slonc','słonecz','slonecz','pogodn','bezchmurn','słonecznie','slonecznie']as$sk)if(strpos($local,$sk)!==false)continue 2;}
        foreach(($t['keywords']??[])as$k)if(strpos($local,$k)!==false)return$t;
    }
    return['icon'=>'cloud','label'=>'Zmienne warunki','panel_classes'=>'bg-slate-600 text-white shadow-slate-500/20','chip_classes'=>'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200','surface_classes'=>'border-slate-200/70 bg-white/72','accent_classes'=>'from-slate-500 via-slate-400 to-sky-400'];
}

function deduplicateSignals($signals){$seen=[];$out=[];foreach($signals as$s){$k=strtolower((string)($s['icon']??'').'|'.(string)($s['label']??''));if(isset($seen[$k]))continue;$seen[$k]=true;$out[]=$s;}return$out;}
function getSunnySignals(){return[['icon'=>'trend-up','label'=>'Trend: poprawa','classes'=>'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200'],['icon'=>'sun','label'=>'Słonecznie','classes'=>'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200']];}

function getForecastSignals($text,$theme){
    $n=toLowercase(removeBoilerplateText($text));$local=removeNonLocalForecastContext($n);$no=hasNoPrecipitationSignal($local)||hasExplicitNoRainSignal($local);$clear=hasClearSkySignal($local);$lowCloud=hasLowCloudSignal($local);$signals=[];$morning=hasStrongMorningSunSignal($local);$rain=!$no&&hasRainSignal($local);$storm=containsAnyKeyword($local,['burz','piorun']);
    $sunny=!$rain&&!$storm&&($morning||(hasPositiveSunSignal($local)&&!hasLessSunSignal($local)));
    if($sunny){foreach(getSunnySignals()as$s)$signals[]=$s;}
    elseif(containsAnyKeyword($local,['ociepl','cieplej','wzrost temperatur','coraz cieplej','wyzsza temperatur','wyższa temperatur'])){
        $signals[]=['icon'=>'trend-up','label'=>'Trend: wzrost','classes'=>'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200'];
        $signals[]=['icon'=>'temp-high','label'=>'Ocieplenie','classes'=>'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200'];
    }elseif(containsAnyKeyword($local,['ochłod','ochlod','chłodniej','chlodniej','spadek temperatur','zimniej'])){
        $signals[]=['icon'=>'trend-down','label'=>'Trend: spadek','classes'=>'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200'];
        $signals[]=['icon'=>'temp-low','label'=>'Ochłodzenie','classes'=>'bg-cyan-50 text-cyan-700 ring-1 ring-inset ring-cyan-200'];
    }else{
        $signals[]=['icon'=>'trend-stable','label'=>'Trend: stabilnie','classes'=>'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200'];
        if(containsAnyKeyword($local,['bez zmian','stabilnie','stabilizacja','temperatura bez zmian','temperatura stabilna']))$signals[]=['icon'=>'trend-stable','label'=>'Stabilnie','classes'=>'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200'];
    }
    $signals[]=['icon'=>$theme['icon'],'label'=>$theme['label'],'classes'=>$theme['chip_classes']];
    if($no)$signals[]=['icon'=>'cloud','label'=>'Bez opadów','classes'=>'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200'];
    if($clear)$signals[]=['icon'=>'sun','label'=>'Bezchmurnie','classes'=>'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200'];
    elseif($lowCloud)$signals[]=['icon'=>'cloud','label'=>'Małe zachmurzenie','classes'=>'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200'];
    if(!$no&&$theme['icon']!=='rain'&&hasRainSignal($local))$signals[]=['icon'=>'rain','label'=>'Opady deszczu','classes'=>'bg-sky-50 text-sky-700 ring-1 ring-inset ring-sky-200'];
    if($theme['icon']!=='wind'&&containsAnyKeyword($local,['silny wiatr','mocniejszy wiatr','poryw','wietrz','wichur']))$signals[]=['icon'=>'wind','label'=>'Silniejszy wiatr','classes'=>'bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-200'];
    if(containsAnyKeyword($local,['upal','upał','goraco','gorąco','cieplo','ciepło','wysoka temperatur']))$signals[]=['icon'=>'temp-high','label'=>'Wyraźnie cieplej','classes'=>'bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200'];
    elseif(containsAnyKeyword($local,['mroz','mróz','przymrozk','zimno','chlodno','chłodno']))$signals[]=['icon'=>'temp-low','label'=>'Chłodno','classes'=>'bg-cyan-50 text-cyan-700 ring-1 ring-inset ring-cyan-200'];
    return array_slice(deduplicateSignals($signals),0,5);
}

function ensureSunnyThursdayLabels($days){
    foreach($days as$i=>$d){
        $title=(string)($d['title']??'');$content=(string)($d['content']??'');$analysis=(string)($d['analysis_text']??'');
        $h=toLowercase($title.' '.strip_tags($content).' '.strip_tags($analysis));if(strpos($h,'czwartek')===false)continue;
        $local=removeNonLocalForecastContext($h);$no=hasNoPrecipitationSignal($local)||hasExplicitNoRainSignal($local);if(!$no&&hasRainSignal($local))continue;
        $morning=hasStrongMorningSunSignal($local);if(!$morning&&(!hasPositiveSunSignal($local)||hasLessSunSignal($local)))continue;
        $days[$i]['theme']=getSunnyForecastTheme();$forced=getSunnySignals();$existing=isset($d['signals'])&&is_array($d['signals'])?$d['signals']:[];
        foreach($existing as$s){$label=(string)($s['label']??'');if($label==='Słonecznie'||strpos($label,'Trend:')===0)continue;$forced[]=$s;}
        $days[$i]['signals']=array_slice(deduplicateSignals($forced),0,5);
    }
    return$days;
}

function buildForecastDay($title,$bodyHtml,$analysisText=null){
    $title=safePregReplace('/\s+/u',' ',trim((string)$title));if(preg_match('/^noc\b/iu',$title)===1)return null;
    $content=capitalizeFirstVisibleLetterInHtml(extractLeadForecastHtml($bodyHtml));
    $plain=html_entity_decode(strip_tags(str_replace(['<br>','<br/>','<br />','</p>'],["\n","\n","\n","\n\n"],(string)$bodyHtml)),ENT_QUOTES,'UTF-8');
    $plain=removeBoilerplateText(safePregReplace('/\s+/u',' ',trim($plain)));
    $source=$analysisText===null?$plain:removeBoilerplateText(safePregReplace('/\s+/u',' ',trim((string)$analysisText)));
    $lead=extractLeadForecastText(strip_tags($content));if($source==='')$source=$lead;if($title===''||$content===''||$source==='')return null;
    $filterSource=$lead!==''?$lead:$source;
    $l=toLowercase($filterSource);
    $night=containsAnyKeyword($l,['przymrozki','przymrozek','mrozowisk','mroz','mróz','szron','w nocy','nocą','nad ranem','przed świtem','temp. rano','minimaln','temperatura spadnie','marzną','zamarznięte','zamarzniete','mroźnie','mroznie'])||preg_match('/\bspadnie\s+do\s+-?\d{1,2}\s*(?:°?\s*c|st\.?\s*c?)\b/u',$l)===1;
    $day=containsAnyKeyword($l,['słońc','slonc','słonecz','slonecz','bezchmurn','słonecznie','slonecznie','słoneczny','sloneczny','słoneczna','sloneczna','słoneczne','sloneczne','od rana słonecznie','od rana slonecznie','od rana słoneczny','od rana sloneczny','w dzień','w ciągu dnia','podczas dnia','w ciągu doby']);
    if(!$day&&isNightOnlyForecastText($title.' '.$filterSource))return null;if($night&&!$day)return null;
    $analysis=$title.' '.$filterSource;$theme=getForecastTheme($analysis);
    return['title'=>$title,'content'=>$content,'theme'=>$theme,'signals'=>getForecastSignals($analysis,$theme),'temperature'=>extractTemperatureSnippet($filterSource),'analysis_text'=>$analysis];
}

function extractForecastSections($content){
    $content=normalizeSourceHtml($content);
    $pattern='/<strong\b[^>]*>\s*((?:<[^>]+>\s*)*(?:PONIEDZIAŁEK|WTOREK|ŚRODA|CZWARTEK|PIĄTEK|SOBOTA|NIEDZIELA|NOC|W NOCY)(?:(?!<\/strong>).)*)<\/strong>/isu';
    $count=preg_match_all($pattern,$content,$m,PREG_OFFSET_CAPTURE);

    if(!$count){
        $days=extractPlainDaySections($content);
        return$days!==[]?['intro'=>'','days'=>$days]:['intro'=>sanitizeForecastHtml($content),'days'=>[]];
    }

    $introRaw=substr($content,0,$m[0][0][1]);
    $days=[];

    for($i=0;$i<$count;$i++){
        $full=$m[0][$i][0];
        $title=trim(strip_tags($m[1][$i][0]));
        $start=$m[0][$i][1]+strlen($full);
        $end=$i<$count-1?$m[0][$i+1][1]:strlen($content);

        $bodyRaw=substr($content,$start,$end-$start);
        $bodyRaw=safePregReplace('/^\s*<\/p>\s*/iu','',$bodyRaw);

        if(preg_match('/^\s*(?:&(?:#8211|ndash);|–|-|:)?\s*[^<\s]/iu',$bodyRaw)===1){
            $bodyRaw='<p>'.$bodyRaw;
        }

        $bodyRaw=safePregReplace('/^\s*(<p\b[^>]*>)?\s*(?:&(?:#8211|ndash);|–|-|:)\s*/iu','$1',$bodyRaw,1);
        $body=sanitizeForecastHtml($bodyRaw);

        if($title===''||$body==='')continue;

        if(($d=buildForecastDay($title,$body))!==null){
            $days[]=$d;
        }
    }

    return['intro'=>sanitizeForecastHtml($introRaw),'days'=>$days];
}

function extractPlainDaySections($content){
    $pattern='/(?:^|\R{2,})\s*((?:PONIEDZIAŁEK|WTOREK|ŚRODA|CZWARTEK|PIĄTEK|SOBOTA|NIEDZIELA|NOC(?:\s+z(?:e)?\s+[\p{L}ąćęłńóśźż]+(?:\s+na\s+[\p{L}ąćęłńóśźż]+)?)?|W\s+NOCY)(?:\s*\[[^\]]+\])?)\s*(?:–|-|:)?/iu';
    $count=preg_match_all($pattern,$content,$m,PREG_OFFSET_CAPTURE);if(!$count)return extractTextDaySections($content);$days=[];
    for($i=0;$i<$count;$i++){
        $title=trim($m[1][$i][0]);$full=$m[0][$i][0];$off=$m[0][$i][1];$start=$off+strpos($full,$title)+strlen($title);$start+=strspn(substr($content,$start)," \t\n\r\0\x0B-–:");$end=$i<$count-1?$m[0][$i+1][1]:strlen($content);
        $body=sanitizeForecastHtml(substr($content,$start,$end-$start));if($title===''||trim(strip_tags($body))==='')continue;if(($d=buildForecastDay($title,$body))!==null)$days[]=$d;
    }
    return$days;
}

function extractTextDaySections($content){
    $text=html_entity_decode(strip_tags(str_replace(['<br>','<br/>','<br />','</p>'],["\n","\n","\n","\n\n"],(string)$content)));
    $text=safePregReplace('/\R{3,}/u',"\n\n",trim(removeBoilerplateText($text)));if($text==='')return[];
    $pattern='/(?:^|\R{2,})\s*(PONIEDZIAŁEK|WTOREK|ŚRODA|CZWARTEK|PIĄTEK|SOBOTA|NIEDZIELA|NOC(?:\s+z(?:e)?\s+[\p{L}ąćęłńóśźż]+(?:\s+na\s+[\p{L}ąćęłńóśźż]+)?)?|W\s+NOCY)(\s*\[[^\]]+\])?\s*(?:–|-|:)?/iu';
    $count=preg_match_all($pattern,$text,$m,PREG_OFFSET_CAPTURE);if(!$count)return[];$days=[];
    for($i=0;$i<$count;$i++){
        $title=trim($m[1][$i][0].($m[2][$i][0]??''));$full=$m[0][$i][0];$off=$m[0][$i][1];$start=$off+strpos($full,$title)+strlen($title);$start+=strspn(substr($text,$start)," \t\n\r\0\x0B-–:");$end=$i<$count-1?$m[0][$i+1][1]:strlen($text);
        $bodyText=extractLeadForecastText(trim(substr($text,$start,$end-$start)));if($title===''||$bodyText==='')continue;$body='<p>'.nl2br(escape($bodyText)).'</p>';if(($d=buildForecastDay($title,$body,$bodyText))!==null)$days[]=$d;
    }
    return$days;
}

function renderForecastIcon($icon,$cls='h-7 w-7'){
    switch($icon){
        case'storm':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25a3.75 3.75 0 0 0-.43-7.47A5.25 5.25 0 0 0 8.98 5.5a4.5 4.5 0 0 0-.83 8.92m4.35-.17-1.65 3.4h2.28l-1.14 3.6 4.01-5.7h-2.25l1.61-3.3" /></svg>';
        case'snow':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15.75h8.5a3.5 3.5 0 1 0-.67-6.93 4.75 4.75 0 0 0-9.17-1.2 3.75 3.75 0 0 0 1.34 7.13Zm1.25 2.75 1 1.5m1.5-3.5v5m3-5-1 1.5m2-1.5 1 1.5m-5.5-.75 1.5.75-1.5.75m1.5-.75h3m1.5-.75-1.5.75 1.5.75" /></svg>';
        case'rain':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 15.75h9a3.75 3.75 0 0 0 .58-7.45 5.25 5.25 0 0 0-10.1-1.22A4.25 4.25 0 0 0 7.5 15.75Zm1.5 2.5-.75 2m3-2-.75 2m3-2-.75 2m3-2-.75 2" /></svg>';
        case'wind':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9.75h11.5a2.75 2.75 0 1 0-2.22-4.37M3 14.25h15a2.25 2.25 0 1 1-1.82 3.57M3 18.25h8.5" /></svg>';
        case'fog':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 9.75h10.5a3.25 3.25 0 1 0-.49-6.46 4.5 4.5 0 0 0-8.58 1.18A3.5 3.5 0 0 0 6.75 9.75Zm-2 4.5h14.5M3.75 18h11.5" /></svg>';
        case'sun':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path stroke-linecap="round" d="M12 2.75v2.5M12 18.75v2.5M4.75 4.75l1.75 1.75M17.5 17.5l1.75 1.75M2.75 12h2.5M18.75 12h2.5M4.75 19.25l1.75-1.75M17.5 6.5l1.75-1.75" /></svg>';
        case'moon':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.25A8.25 8.25 0 0 1 9.75 3.75a8.25 8.25 0 1 0 10.5 10.5Z" /></svg>';
        case'trend-up':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 15 5-5 4 4 5-6M16 8h3v3" /></svg>';
        case'trend-down':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 9 5 5 4-4 5 6M16 16h3v-3" /></svg>';
        case'trend-stable':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12h16m-4-3 4 3-4 3M8 9l-4 3 4 3" /></svg>';
        case'temp-high':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14.25V5.5a2 2 0 1 0-4 0v8.75a4 4 0 1 0 4 0Zm-2-.75h2" /><path stroke-linecap="round" d="M16.5 7.5h3m-1.5-1.5v3M16.5 12h3" /></svg>';
        case'temp-low':return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14.25V5.5a2 2 0 1 0-4 0v8.75a4 4 0 1 0 4 0Zm-2-.75h2" /><path stroke-linecap="round" d="M16.5 9h3M16.5 12h3M16.5 15h3" /></svg>';
        default:return'<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 16.25h9a3.75 3.75 0 0 0 .58-7.45 5.25 5.25 0 0 0-10.1-1.22A4.25 4.25 0 0 0 7.5 16.25Z" /></svg>';
    }
}

function formatPublishDate($v){if(!$v)return'brak daty';try{$d=new DateTimeImmutable((string)$v);return$d->format('d.m.Y');}catch(Exception $e){return'brak daty';}}
function renderOverviewPill($label,$value,$icon='cloud'){return'<div class="dashboard-tile rounded-[28px] border border-white/20 bg-white/12 px-4 py-4 text-white shadow-sm backdrop-blur-xl"><div class="flex items-center gap-2 text-[11px] font-black uppercase tracking-[0.24em] text-sky-100/85">'.renderForecastIcon($icon,'h-4 w-4').'<span>'.escape($label).'</span></div><div class="mt-2 text-2xl font-black tracking-tight text-white">'.escape($value).'</div></div>';}

function telegramTextLength($text){
    return function_exists('mb_strlen')?mb_strlen((string)$text,'UTF-8'):strlen((string)$text);
}

function telegramTextSubstring($text,$start,$length=null){
    $text=(string)$text;
    if(function_exists('mb_substr')){
        if($length===null)$length=max(0,telegramTextLength($text)-$start);
        return mb_substr($text,$start,$length,'UTF-8');
    }
    return $length===null?substr($text,$start):substr($text,$start,$length);
}

function plainTextFromHtml($html){
    $text=html_entity_decode(strip_tags(str_replace(['<br>','<br/>','<br />','</p>'],["\n","\n","\n","\n\n"],(string)$html)),ENT_QUOTES,'UTF-8');
    $text=safePregReplace('/[ \t]+/u',' ',$text);
    $text=safePregReplace('/\n{3,}/u',"\n\n",$text);
    return trim($text);
}

function getForecastDisplayIntroHtml($intro){return capitalizeFirstVisibleLetterInHtml((string)$intro);}
function getForecastDisplayIntroText($intro){return plainTextFromHtml(getForecastDisplayIntroHtml($intro));}
function hasForecastDisplayIntro($intro){return getForecastDisplayIntroText($intro)!=='';}

function shortenTelegramText($text,$limit=700){
    $text=trim((string)$text);
    if(telegramTextLength($text)<=$limit)return$text;
    return rtrim(telegramTextSubstring($text,0,$limit-3)).'...';
}

function loadTelegramState(){
    if(!is_file(TELEGRAM_STATE_FILE))return[];
    $raw=file_get_contents(TELEGRAM_STATE_FILE);
    if($raw===false||trim($raw)==='')return[];
    $data=json_decode($raw,true);
    return is_array($data)?$data:[];
}

function saveTelegramState($state){
    $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    if($json===false)return false;
    return file_put_contents(TELEGRAM_STATE_FILE,$json,LOCK_EX)!==false;
}

function buildParsedForecastFingerprint($dateDisplay,$intro,$forecastDays){
    $payload=['date'=>$dateDisplay,'intro'=>getForecastDisplayIntroText($intro),'days'=>[]];

    foreach($forecastDays as$day){
        $signals=[];

        if(isset($day['signals'])&&is_array($day['signals'])){
            foreach($day['signals']as$signal){
                $signals[]=(string)($signal['label']??'');
            }
        }

        $payload['days'][]=[
            'title'=>(string)($day['title']??''),
            'content'=>plainTextFromHtml($day['content']??''),
            'theme'=>(string)($day['theme']['label']??''),
            'temperature'=>(string)($day['temperature']??''),
            'signals'=>$signals
        ];
    }

    return hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE));
}

function normalizeForecastTitleForTelegram($title){
    $title=trim(html_entity_decode((string)$title,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if(preg_match('/^(.*?)\s*\[([^\]]+)\]\s*(?:[–—\-:]\s*.+)?$/u',$title,$m)===1)return trim($m[1]).' ('.trim($m[2]).')';
    return$title;
}
function extractTitleNoteForTelegram($title){
    $title=trim(html_entity_decode((string)$title,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if(preg_match('/^.*?\[([^\]]+)\]\s*[–—\-:]\s*(.+)$/u',$title,$m)===1)return trim($m[2]);
    return'';
}


function telegramEmojiForForecastIcon($icon){
    switch((string)$icon){
        case'trend-up':return'📈';
        case'trend-down':return'📉';
        case'trend-stable':return'⚖️';
        case'sun':return'☀️';
        case'cloud':return'⛅';
        case'rain':return'🌧️';
        case'storm':return'⛈️';
        case'wind':return'💨';
        case'fog':return'🌫️';
        case'temp-high':return'🔥';
        case'temp-low':return'🥶';
        case'snow':return'❄️';
        case'moon':return'🌙';
    }
    return'';
}

function formatTelegramForecastTag($tag){
    if(!is_array($tag))return'';
    $label=trim((string)($tag['label']??''));
    if($label==='')return'';

    $emoji=telegramEmojiForForecastIcon((string)($tag['icon']??''));

    return $emoji===''?$label:$emoji.' '.$label;
}

function getForecastDisplaySignals($day){
    $signals=[];$seen=[];
    if(!isset($day['signals'])||!is_array($day['signals']))return$signals;
    foreach($day['signals']as$signal){
        if(!is_array($signal))continue;
        $label=trim((string)($signal['label']??''));
        if($label==='')continue;
        $signal['label']=$label;
        $signal['icon']=(string)($signal['icon']??'');
        $signal['classes']=(string)($signal['classes']??'');
        $key=toLowercase($signal['icon'].'|'.$signal['label']);
        if(isset($seen[$key]))continue;
        $seen[$key]=true;
        $signals[]=$signal;
    }
    return$signals;
}

function buildTelegramParsedForecastMessage($dateDisplay,$intro,$forecastDays){
    $pageUrl=envValue('PUBLIC_PAGE_URL');
    $lines=[];
    $lines[]='🌦 Pogoda dla Śląska';
    $lines[]='Nowa aktualizacja prognozy';
    $lines[]='';
    $lines[]='Aktualizacja: '.$dateDisplay;

    $introText=getForecastDisplayIntroText($intro);

    if($introText!==''){
        $lines[]='';
        $lines[]='Wstęp:';
        $lines[]=shortenTelegramText($introText,650);
    }

    if($forecastDays!==[]){
        $lines[]='';
        $lines[]='Prognoza:';

        foreach($forecastDays as$day){
            $title=normalizeForecastTitleForTelegram($day['title']??'');
            $titleNote=extractTitleNoteForTelegram($day['title']??'');
            $themeTag=isset($day['theme'])&&is_array($day['theme'])?$day['theme']:[];
            $temperature=trim((string)($day['temperature']??''));
            $content=plainTextFromHtml($day['content']??'');
            if($titleNote!=='')$content=$titleNote.' – '.$content;

            $header='➡️ '.$title;
            $details=[];

            $themeLabel=formatTelegramForecastTag($themeTag);
            if($themeLabel!=='')$details[]=$themeLabel;
            if($temperature!=='')$details[]=$temperature;
            if($details!==[])$header.=' — '.implode(', ',$details);

            $lines[]='';
            $lines[]=$header;

            if($content!=='')$lines[]=shortenTelegramText($content,850);

            $signals=getForecastDisplaySignals($day);

            if($signals!==[]){
                $signals=array_values(array_filter(array_map('formatTelegramForecastTag',$signals),'strlen'));
                if($signals!==[])$lines[]='Tagi: '.implode('  ',array_slice($signals,0,5));
            }
        }
    }

    if($pageUrl!==''){
        $lines[]='';
        $lines[]='Pełna prognoza:';
        $lines[]=$pageUrl;
    }

    return trim(implode("\n",$lines));
}

function splitTelegramMessage($text,$limit=3900){
    $text=trim((string)$text);
    if($text==='')return[];
    if(telegramTextLength($text)<=$limit)return[$text];

    $parts=[];
    $current='';
    $paragraphs=preg_split('/\n{2,}/u',$text)?:[$text];

    foreach($paragraphs as$paragraph){
        $paragraph=trim($paragraph);
        if($paragraph==='')continue;

        $candidate=$current===''?$paragraph:$current."\n\n".$paragraph;

        if(telegramTextLength($candidate)<=$limit){
            $current=$candidate;
            continue;
        }

        if($current!==''){
            $parts[]=$current;
            $current='';
        }

        if(telegramTextLength($paragraph)<=$limit){
            $current=$paragraph;
            continue;
        }

        $offset=0;
        $length=telegramTextLength($paragraph);

        while($offset<$length){
            $parts[]=telegramTextSubstring($paragraph,$offset,$limit);
            $offset+=$limit;
        }
    }

    if($current!=='')$parts[]=$current;

    return$parts;
}

function sendTelegramMessage($text){
    $token=envValue('TELEGRAM_BOT_TOKEN');
    $chatId=envValue('TELEGRAM_CHAT_ID');

    if($token===''||$chatId==='')return['ok'=>false,'error'=>'Brak TELEGRAM_BOT_TOKEN albo TELEGRAM_CHAT_ID.'];
    if(!function_exists('curl_init'))return['ok'=>false,'error'=>'Na serwerze nie jest dostępne rozszerzenie cURL.'];

    foreach(splitTelegramMessage($text)as$part){
        $url='https://api.telegram.org/bot'.$token.'/sendMessage';
        $payload=['chat_id'=>$chatId,'text'=>$part,'disable_web_page_preview'=>false];

        $ch=curl_init($url);

        if($ch===false)return['ok'=>false,'error'=>'Nie udało się zainicjować cURL dla Telegrama.'];

        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>15
        ]);

        $body=curl_exec($ch);

        if($body===false){
            $error=curl_error($ch);
            curl_close($ch);
            return['ok'=>false,'error'=>'Błąd wysyłki Telegram: '.$error];
        }

        $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($code>=400)return['ok'=>false,'error'=>'Telegram zwrócił HTTP '.$code.': '.$body];
    }

    return['ok'=>true,'error'=>null];
}

function handleTelegramParsedForecastNotification($dateDisplay,$intro,$forecastDays,$errorMessage){
    if($errorMessage!==null){
        fwrite(STDERR,'Błąd pobierania prognozy: '.$errorMessage.PHP_EOL);
        exit(1);
    }

    if($forecastDays===[]&&!hasForecastDisplayIntro($intro)){
        fwrite(STDERR,'Brak sparsowanych danych do wysłania.'.PHP_EOL);
        exit(1);
    }

    $fingerprint=buildParsedForecastFingerprint($dateDisplay,$intro,$forecastDays);
    $state=loadTelegramState();
    $lastFingerprint=isset($state['last_fingerprint'])?(string)$state['last_fingerprint']:'';

    if($lastFingerprint===$fingerprint&&!isTelegramForceMode()){
        echo'Brak nowej sparsowanej prognozy.'.PHP_EOL;
        exit(0);
    }

    if($lastFingerprint===''&&!isTelegramForceMode()){
        saveTelegramState([
            'last_fingerprint'=>$fingerprint,
            'last_checked_at'=>date(DATE_ATOM),
            'last_sent_at'=>null
        ]);

        echo'Pierwsze uruchomienie: zapisano aktualny stan bez wysyłki.'.PHP_EOL;
        echo'Test wysyłki: php index.php --telegram --force'.PHP_EOL;
        exit(0);
    }

    $message=buildTelegramParsedForecastMessage($dateDisplay,$intro,$forecastDays);
    $result=sendTelegramMessage($message);

    if(!$result['ok']){
        fwrite(STDERR,$result['error'].PHP_EOL);
        exit(1);
    }

    saveTelegramState([
        'last_fingerprint'=>$fingerprint,
        'last_checked_at'=>date(DATE_ATOM),
        'last_sent_at'=>date(DATE_ATOM)
    ]);

    echo'Wysłano sparsowaną prognozę na Telegram.'.PHP_EOL;
    exit(0);
}

$errorMessage=null;$intro='';$dateDisplay='brak daty';$forecastDays=[];
$feedResult=fetchWeatherFeed(RSS_URL);
if($feedResult['body']===null)$errorMessage=$feedResult['error']??'Nie udało się pobrać danych pogodowych.';
else{
    $rss=parseRss($feedResult['body']);
    if(!($rss instanceof SimpleXMLElement)||!isset($rss->channel->item[0]))$errorMessage='Nie udało się odczytać struktury kanału RSS.';
    else{
        $item=$rss->channel->item[0];$dateDisplay=formatPublishDate(isset($item->pubDate)?(string)$item->pubDate:null);
        $sections=extractForecastSections(extractItemContent($item));$intro=$sections['intro'];$forecastDays=$sections['days'];
        if($forecastDays===[]&&trim(strip_tags($intro))!==''){$theme=getForecastTheme(strip_tags($intro));$forecastDays[]=['title'=>'Prognoza ogólna','content'=>capitalizeFirstVisibleLetterInHtml($intro),'theme'=>$theme,'signals'=>getForecastSignals(strip_tags($intro),$theme),'temperature'=>extractTemperatureSnippet(strip_tags($intro)),'analysis_text'=>strip_tags($intro)];$intro='';}
        if($forecastDays===[]&&$errorMessage===null)$errorMessage='Prognoza została pobrana, ale nie udało się wydzielić sekcji dziennych.';
    }
}
$forecastDays=ensureSunnyThursdayLabels($forecastDays);
$hasIntro=hasForecastDisplayIntro($intro);
if(isTelegramNotifyMode()){
    handleTelegramParsedForecastNotification($dateDisplay,$intro,$forecastDays,$errorMessage);
}
$faviconSvg=<<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128">
    <defs>
        <radialGradient id="orb" cx="34%" cy="24%" r="78%">
            <stop offset="0" stop-color="#f8fafc"/>
            <stop offset=".20" stop-color="#7dd3fc"/>
            <stop offset=".42" stop-color="#38bdf8"/>
            <stop offset=".62" stop-color="#818cf8"/>
            <stop offset=".82" stop-color="#1e293b"/>
            <stop offset="1" stop-color="#050816"/>
        </radialGradient>
        <radialGradient id="shine" cx="32%" cy="24%" r="42%">
            <stop offset="0" stop-color="#ffffff" stop-opacity=".82"/>
            <stop offset=".46" stop-color="#ffffff" stop-opacity=".26"/>
            <stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
        </radialGradient>
        <linearGradient id="rim" x1="24" y1="18" x2="108" y2="112" gradientUnits="userSpaceOnUse">
            <stop stop-color="#bae6fd" stop-opacity=".82"/>
            <stop offset=".48" stop-color="#818cf8" stop-opacity=".58"/>
            <stop offset="1" stop-color="#34d399" stop-opacity=".60"/>
        </linearGradient>
    </defs>
    <circle cx="64" cy="64" r="52" fill="url(#orb)"/>
    <circle cx="64" cy="64" r="51" fill="none" stroke="url(#rim)" stroke-width="3"/>
    <circle cx="47" cy="39" r="30" fill="url(#shine)"/>
    <path d="M25 80c18 20 58 30 86 1-7 28-30 43-57 39-18-3-29-14-29-40Z" fill="#ffffff" opacity=".08"/>
</svg>
SVG;
$faviconHref='data:image/svg+xml,'.rawurlencode($faviconSvg);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#050816"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="<?php echo escape(APP_TITLE); ?>"><meta name="apple-mobile-web-app-status-bar-style" content="default">
<title><?php echo escape(APP_TITLE); ?></title>
<link rel="icon" type="image/svg+xml" href="<?php echo escape($faviconHref); ?>">
<link rel="preconnect" href="https://cdn.jsdelivr.net"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><script src="https://cdn.tailwindcss.com"></script>
<script>document.documentElement.classList.add('js');tailwind.config={theme:{extend:{boxShadow:{soft:'0 28px 90px rgba(15, 23, 42, 0.16)',card:'0 24px 60px rgba(15, 23, 42, 0.12)',glow:'0 20px 80px rgba(56, 189, 248, 0.22)'}}}};</script>
<style>
:root{
    color-scheme:light;
    --ink:#0f172a;
    --muted:#64748b;
    --panel:rgba(255,255,255,.62);
    --panel-strong:rgba(255,255,255,.82);
    --panel-soft:rgba(255,255,255,.42);
    --line:rgba(255,255,255,.64);
    --line-dark:rgba(15,23,42,.10);
    --shadow:0 28px 90px rgba(15,23,42,.16);
    --shadow-soft:0 18px 48px rgba(15,23,42,.10);
    --radius-xl:2rem;
    --radius-2xl:2.5rem;
}
*{min-width:0}
html{scroll-padding-top:1.5rem}
body{
    margin:0;
    overflow-x:hidden;
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    color:var(--ink);
    background:
        radial-gradient(circle at 12% 8%,rgba(56,189,248,.42),transparent 24rem),
        radial-gradient(circle at 82% 10%,rgba(129,140,248,.34),transparent 28rem),
        radial-gradient(circle at 52% 98%,rgba(45,212,191,.20),transparent 32rem),
        linear-gradient(135deg,#e0f7ff 0%,#f8fafc 42%,#eef2ff 100%);
    background-attachment:fixed;
    -webkit-font-smoothing:antialiased;
    -moz-osx-font-smoothing:grayscale;
}
body:before{
    content:"";
    position:fixed;
    inset:0;
    pointer-events:none;
    z-index:-3;
    background:
        linear-gradient(rgba(15,23,42,.035) 1px,transparent 1px),
        linear-gradient(90deg,rgba(15,23,42,.035) 1px,transparent 1px);
    background-size:44px 44px;
    mask-image:linear-gradient(to bottom,rgba(0,0,0,.70),transparent 74%);
}
body:after{
    content:"";
    position:fixed;
    inset:auto 7vw 4vh 7vw;
    height:20rem;
    pointer-events:none;
    z-index:-2;
    border-radius:999px;
    background:
        radial-gradient(circle at 20% 50%,rgba(14,165,233,.20),transparent 18rem),
        radial-gradient(circle at 52% 50%,rgba(99,102,241,.18),transparent 20rem),
        radial-gradient(circle at 82% 50%,rgba(16,185,129,.16),transparent 18rem);
    filter:blur(26px);
}
main.container{
    max-width:1480px;
    padding-inline:clamp(.9rem,2vw,1.5rem);
}
.weather-shell{
    position:relative;
    overflow:hidden;
    isolation:isolate;
    border-radius:clamp(1.7rem,3.2vw,3rem);
    border:1px solid rgba(255,255,255,.72);
    background:
        linear-gradient(135deg,rgba(255,255,255,.70),rgba(255,255,255,.36)),
        rgba(255,255,255,.45);
    box-shadow:var(--shadow);
    backdrop-filter:blur(28px) saturate(145%);
    -webkit-backdrop-filter:blur(28px) saturate(145%);
}
.weather-shell:before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    z-index:0;
    background:
        radial-gradient(circle at 10% 6%,rgba(255,255,255,.70),transparent 22rem),
        radial-gradient(circle at 96% 16%,rgba(14,165,233,.13),transparent 24rem),
        linear-gradient(180deg,rgba(255,255,255,.18),transparent 34%);
}
.weather-shell>*{
    position:relative;
    z-index:1;
}
.hero-surface{
    position:relative;
    overflow:hidden;
    isolation:isolate;
    color:#fff;
    background:
        radial-gradient(circle at 82% 18%,rgba(56,189,248,.46),transparent 20rem),
        radial-gradient(circle at 16% 90%,rgba(129,140,248,.34),transparent 22rem),
        linear-gradient(135deg,#020617 0%,#0f172a 46%,#172554 100%);
}
.hero-surface:before,
.hero-surface:after{
    content:"";
    position:absolute;
    pointer-events:none;
    z-index:-1;
    border-radius:999px;
}
.hero-surface:before{
    width:23rem;
    height:23rem;
    right:-6rem;
    top:-9rem;
    background:rgba(14,165,233,.42);
    filter:blur(14px);
}
.hero-surface:after{
    width:26rem;
    height:26rem;
    left:-11rem;
    bottom:-15rem;
    background:rgba(99,102,241,.30);
    filter:blur(16px);
}
.page-pad{
    padding:clamp(1rem,1.9vw,1.75rem);
}
.hero-pad{
    padding:clamp(1.25rem,2.6vw,2.7rem);
}
.hero-topbar{
    width:100%;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:1.25rem;
}
.hero-update{
    margin-left:auto;
    flex:0 0 auto;
    min-width:min(100%,17rem);
}
.hero-update .dashboard-tile{
    width:100%;
}
.hero-title{
    margin:0;
    max-width:58rem;
    line-height:.92;
    letter-spacing:-.078em;
    text-wrap:balance;
    text-shadow:0 14px 36px rgba(0,0,0,.30);
}
.dashboard-grid{
    display:grid;
    grid-template-columns:repeat(1,minmax(0,1fr));
    gap:.85rem;
}
.dashboard-tile{
    position:relative;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.24)!important;
    background:
        linear-gradient(135deg,rgba(255,255,255,.18),rgba(255,255,255,.08))!important;
    box-shadow:0 18px 46px rgba(2,6,23,.22);
}
.dashboard-tile:after{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:linear-gradient(135deg,rgba(255,255,255,.28),transparent 48%);
}
.glass-card{
    border:1px solid rgba(255,255,255,.68);
    background:
        linear-gradient(135deg,rgba(255,255,255,.78),rgba(255,255,255,.48));
    box-shadow:var(--shadow-soft);
    backdrop-filter:blur(22px) saturate(140%);
    -webkit-backdrop-filter:blur(22px) saturate(140%);
}
.source-panel{
    position:relative;
    overflow:hidden;
    background:
        radial-gradient(circle at top right,rgba(14,165,233,.18),transparent 16rem),
        linear-gradient(135deg,rgba(255,255,255,.82),rgba(255,255,255,.52));
}
.source-panel:before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:linear-gradient(135deg,rgba(255,255,255,.42),transparent 45%);
}
.source-panel>*{
    position:relative;
    z-index:1;
}
.forecast-rail{
    overflow-x:auto;
    overflow-y:visible;
    padding:.55rem .4rem 1.05rem;
    margin-inline:-.4rem;
    scroll-snap-type:x mandatory;
    scrollbar-width:thin;
    scrollbar-color:rgba(15,23,42,.25) transparent;
}
.forecast-rail::-webkit-scrollbar{
    height:.7rem;
}
.forecast-rail::-webkit-scrollbar-track{
    border-radius:999px;
    background:rgba(255,255,255,.35);
}
.forecast-rail::-webkit-scrollbar-thumb{
    border-radius:999px;
    background:rgba(15,23,42,.22);
    border:2px solid rgba(255,255,255,.45);
}
.forecast-list{
    display:grid;
    grid-auto-flow:column;
    grid-auto-columns:minmax(19rem,25.5rem);
    gap:1rem;
    align-items:stretch;
}
.forecast-card{
    position:relative;
    overflow:hidden;
    min-height:100%;
    border-radius:clamp(1.45rem,2.5vw,2.15rem);
    border-color:rgba(255,255,255,.68)!important;
    background:
        linear-gradient(145deg,rgba(255,255,255,.82),rgba(255,255,255,.50))!important;
    box-shadow:0 24px 64px rgba(15,23,42,.12);
    transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease;
    content-visibility:auto;
    contain-intrinsic-size:1px 470px;
    scroll-snap-align:start;
    backdrop-filter:blur(22px) saturate(150%);
    -webkit-backdrop-filter:blur(22px) saturate(150%);
}
.forecast-card:before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        linear-gradient(135deg,rgba(255,255,255,.78),transparent 38%),
        radial-gradient(circle at 94% 0%,rgba(255,255,255,.74),transparent 9.5rem),
        radial-gradient(circle at 4% 100%,rgba(14,165,233,.10),transparent 12rem);
}
.forecast-card:after{
    content:"";
    position:absolute;
    left:1.35rem;
    right:1.35rem;
    top:.75rem;
    height:.28rem;
    border-radius:999px;
    background:linear-gradient(90deg,rgba(14,165,233,.52),rgba(99,102,241,.42),rgba(245,158,11,.38));
}
.forecast-card-inner{
    position:relative;
    z-index:1;
    height:100%;
    display:flex;
    flex-direction:column;
}
.forecast-icon{
    width:3.55rem;
    height:3.55rem;
    border-radius:1.35rem;
    flex-shrink:0;
    box-shadow:0 18px 38px rgba(15,23,42,.18),inset 0 1px 0 rgba(255,255,255,.28);
}
.forecast-title{
    line-height:.98;
    letter-spacing:-.045em;
    text-wrap:balance;
}
.forecast-copy,
.intro-copy{
    font-size:.98rem;
    line-height:1.76;
}
.forecast-copy p,
.intro-copy p{
    margin-bottom:.9rem;
}
.forecast-copy p:last-child,
.intro-copy p:last-child{
    margin-bottom:0;
}
.forecast-copy ul,
.forecast-copy ol,
.intro-copy ul,
.intro-copy ol{
    margin:.85rem 0;
    padding-left:1.25rem;
}
.forecast-copy li,
.intro-copy li{
    margin-bottom:.35rem;
}
.forecast-copy strong,
.intro-copy strong{
    color:#020617;
    font-weight:850;
}
.forecast-copy-panel{
    border-color:rgba(255,255,255,.78)!important;
    background:
        linear-gradient(135deg,rgba(255,255,255,.72),rgba(255,255,255,.46))!important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.66),0 16px 38px rgba(15,23,42,.07)!important;
    backdrop-filter:blur(14px);
    -webkit-backdrop-filter:blur(14px);
}
.forecast-toggle{
    display:none;
    width:100%;
    min-height:3.15rem;
    align-items:center;
    justify-content:space-between;
    gap:1rem;
    border:1px solid rgba(255,255,255,.72);
    border-radius:1.1rem;
    background:rgba(255,255,255,.74);
    padding:.9rem 1rem;
    color:#0f172a;
    font-size:.94rem;
    font-weight:900;
    text-align:left;
    box-shadow:0 14px 34px rgba(15,23,42,.09);
    backdrop-filter:blur(14px);
    -webkit-backdrop-filter:blur(14px);
}
.forecast-toggle-icon{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:1.85rem;
    height:1.85rem;
    flex:0 0 auto;
    border-radius:999px;
    background:rgba(226,232,240,.85);
}
.forecast-toggle svg{
    transition:transform .2s ease;
}
.forecast-card.is-open .forecast-toggle svg{
    transform:rotate(180deg);
}
.footer-surface{
    border-top:1px solid rgba(255,255,255,.68);
    background:
        linear-gradient(135deg,rgba(248,250,252,.84),rgba(255,255,255,.50));
    backdrop-filter:blur(18px) saturate(135%);
    -webkit-backdrop-filter:blur(18px) saturate(135%);
}
.footer-meta-link{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:.55rem;
    min-height:2.25rem;
    padding:.55rem .95rem;
    border:1px solid rgba(15,23,42,.10);
    border-radius:999px;
    background:rgba(255,255,255,.78);
    color:#0f172a;
    text-decoration:none;
    font-weight:800;
    box-shadow:0 10px 30px rgba(15,23,42,.08);
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease,background .2s ease,color .2s ease;
}
.footer-meta-link svg{
    width:1rem;
    height:1rem;
    flex:0 0 auto;
}
.footer-meta-link:hover{
    transform:translateY(-1px);
    border-color:rgba(15,23,42,.18);
    background:rgba(255,255,255,.94);
    color:#020617;
    box-shadow:0 16px 36px rgba(15,23,42,.10);
}
.footer-meta-link:focus-visible{
    outline:2px solid rgba(14,165,233,.55);
    outline-offset:2px;
}
@media(min-width:640px){
    .dashboard-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
    .forecast-list{grid-auto-columns:minmax(21rem,27.5rem)}
}
@media(min-width:1200px){
    .forecast-list{grid-auto-columns:minmax(22rem,28.5rem)}
}
@media(hover:hover) and (pointer:fine){
    .forecast-card:hover{
        transform:translateY(-5px);
        border-color:rgba(255,255,255,.88)!important;
        box-shadow:0 36px 78px rgba(15,23,42,.17);
    }
}
@media(max-width:575.98px){
    .page-pad{padding:1rem}
    .hero-topbar{flex-direction:column;align-items:stretch}
    .hero-update{width:100%;min-width:0;margin-left:0}
    .hero-title{font-size:2.55rem;letter-spacing:-.07em}
    .forecast-list{grid-auto-columns:minmax(17.5rem,86vw)}
    .forecast-icon{width:3rem;height:3rem;border-radius:1rem}
    .forecast-title{font-size:1.45rem;line-height:1.04}
    .forecast-copy,.intro-copy{font-size:.95rem;line-height:1.7}
    html.js .forecast-toggle{display:inline-flex}
    html.js .forecast-card[data-forecast-card]:not(.is-open) .forecast-copy-panel{display:none}
}
@media(max-width:991.98px),(hover:none),(pointer:coarse){
    .glass-card,
    .forecast-card,
    .weather-shell,
    .forecast-toggle,
    .footer-surface{
        backdrop-filter:none;
        -webkit-backdrop-filter:none;
    }
    .forecast-card{transition:none}
}
@media(prefers-reduced-motion:reduce){
    *,
    *:before,
    *:after{
        animation:none!important;
        transition:none!important;
        scroll-behavior:auto!important;
    }
}

</style>



<style id="modern-glass-overrides">
:root {
    color-scheme: dark;
    --glass-page: #050816;
    --glass-ink: #f8fafc;
    --glass-muted: #a9b4c7;
    --glass-subtle: #7b879c;
    --glass-line: rgba(255, 255, 255, .14);
    --glass-line-strong: rgba(255, 255, 255, .26);
    --glass-card: rgba(255, 255, 255, .105);
    --glass-card-strong: rgba(255, 255, 255, .16);
    --glass-card-soft: rgba(255, 255, 255, .075);
    --glass-blue: #38bdf8;
    --glass-violet: #818cf8;
    --glass-emerald: #34d399;
    --glass-amber: #fbbf24;
    --glass-radius: 34px;
    --glass-radius-lg: 44px;
    --glass-shadow: 0 32px 110px rgba(0, 0, 0, .44);
    --glass-shadow-card: 0 24px 70px rgba(0, 0, 0, .30);
}

html,
body {
    background: var(--glass-page) !important;
}

body {
    min-height: 100vh !important;
    color: var(--glass-ink) !important;
    background:
        radial-gradient(circle at 12% 8%, rgba(56, 189, 248, .32), transparent 28rem),
        radial-gradient(circle at 88% 8%, rgba(129, 140, 248, .30), transparent 30rem),
        radial-gradient(circle at 50% 105%, rgba(52, 211, 153, .18), transparent 34rem),
        linear-gradient(145deg, #020617 0%, #07111f 42%, #0f172a 100%) !important;
    background-attachment: fixed !important;
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
}

body::before {
    content: "" !important;
    position: fixed !important;
    inset: 0 !important;
    z-index: -3 !important;
    pointer-events: none !important;
    background:
        linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px) !important;
    background-size: 52px 52px !important;
    mask-image: linear-gradient(to bottom, rgba(0, 0, 0, .82), transparent 78%) !important;
}

body::after {
    content: "" !important;
    position: fixed !important;
    inset: auto 8vw 4vh 8vw !important;
    height: 24rem !important;
    z-index: -2 !important;
    pointer-events: none !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 20% 50%, rgba(14, 165, 233, .24), transparent 19rem),
        radial-gradient(circle at 54% 50%, rgba(99, 102, 241, .22), transparent 22rem),
        radial-gradient(circle at 84% 50%, rgba(16, 185, 129, .18), transparent 18rem) !important;
    filter: blur(34px) !important;
}

main.container {
    max-width: 1520px !important;
    padding: clamp(14px, 2vw, 30px) !important;
}

.weather-shell {
    position: relative !important;
    isolation: isolate !important;
    overflow: hidden !important;
    border: 1px solid var(--glass-line-strong) !important;
    border-radius: var(--glass-radius-lg) !important;
    background:
        linear-gradient(145deg, rgba(255, 255, 255, .16), rgba(255, 255, 255, .06)),
        rgba(255, 255, 255, .07) !important;
    box-shadow: var(--glass-shadow) !important;
    backdrop-filter: blur(34px) saturate(160%) !important;
    -webkit-backdrop-filter: blur(34px) saturate(160%) !important;
}

.weather-shell::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    z-index: 0 !important;
    pointer-events: none !important;
    background:
        radial-gradient(circle at 8% 7%, rgba(255, 255, 255, .18), transparent 20rem),
        radial-gradient(circle at 92% 10%, rgba(56, 189, 248, .18), transparent 24rem),
        linear-gradient(180deg, rgba(255, 255, 255, .08), transparent 40%) !important;
}

.weather-shell > * {
    position: relative !important;
    z-index: 1 !important;
}

.hero-surface {
    position: relative !important;
    overflow: hidden !important;
    min-height: 300px !important;
    border: 1px solid rgba(255, 255, 255, .10) !important;
    border-radius: calc(var(--glass-radius-lg) - 1px) calc(var(--glass-radius-lg) - 1px) var(--glass-radius) var(--glass-radius) !important;
    background:
        radial-gradient(circle at 82% 20%, rgba(56, 189, 248, .44), transparent 22rem),
        radial-gradient(circle at 22% 100%, rgba(129, 140, 248, .30), transparent 24rem),
        linear-gradient(135deg, rgba(2, 6, 23, .94), rgba(15, 23, 42, .72)),
        rgba(255, 255, 255, .08) !important;
}

.hero-surface::before {
    content: "" !important;
    position: absolute !important;
    width: 24rem !important;
    height: 24rem !important;
    right: -6rem !important;
    top: -10rem !important;
    border-radius: 999px !important;
    background: rgba(56, 189, 248, .34) !important;
    filter: blur(12px) !important;
    pointer-events: none !important;
}

.hero-surface::after {
    content: "" !important;
    position: absolute !important;
    left: clamp(18px, 3vw, 42px) !important;
    right: clamp(18px, 3vw, 42px) !important;
    bottom: 0 !important;
    height: 1px !important;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .34), transparent) !important;
    pointer-events: none !important;
}

.hero-pad {
    padding: clamp(24px, 4vw, 56px) !important;
}

.hero-topbar {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) minmax(230px, 300px) !important;
    align-items: end !important;
    gap: clamp(22px, 4vw, 52px) !important;
}

.hero-topbar::before {
    content: "Prognoza krótkoterminowa" !important;
    grid-column: 1 / -1 !important;
    width: fit-content !important;
    display: inline-flex !important;
    align-items: center !important;
    min-height: 34px !important;
    padding: 9px 13px !important;
    border: 1px solid rgba(255, 255, 255, .18) !important;
    border-radius: 999px !important;
    color: #dbeafe !important;
    background: rgba(255, 255, 255, .10) !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, .13) !important;
    font-size: 11px !important;
    font-weight: 950 !important;
    letter-spacing: .24em !important;
    line-height: 1 !important;
    text-transform: uppercase !important;
    backdrop-filter: blur(18px) !important;
    -webkit-backdrop-filter: blur(18px) !important;
}

.hero-title {
    max-width: 780px !important;
    margin: 0 !important;
    color: #ffffff !important;
    font-size: clamp(44px, 7vw, 96px) !important;
    line-height: .88 !important;
    letter-spacing: -.085em !important;
    text-wrap: balance !important;
    text-shadow: 0 24px 70px rgba(0, 0, 0, .45) !important;
}

.hero-update {
    width: 100% !important;
    min-width: 0 !important;
    margin-left: 0 !important;
}

.hero-update .dashboard-tile {
    width: 100% !important;
}

.dashboard-tile {
    position: relative !important;
    overflow: hidden !important;
    border: 1px solid rgba(255, 255, 255, .18) !important;
    border-radius: 26px !important;
    background:
        linear-gradient(145deg, rgba(255, 255, 255, .19), rgba(255, 255, 255, .075)) !important;
    box-shadow: 0 24px 60px rgba(0, 0, 0, .24) !important;
    backdrop-filter: blur(22px) saturate(160%) !important;
    -webkit-backdrop-filter: blur(22px) saturate(160%) !important;
}

.dashboard-tile::after {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    pointer-events: none !important;
    background: linear-gradient(135deg, rgba(255, 255, 255, .22), transparent 42%) !important;
}

.dashboard-tile .text-white,
.dashboard-tile .text-sky-100\/85 {
    color: #f8fafc !important;
}

.page-pad {
    padding: clamp(18px, 2.8vw, 42px) !important;
}

.glass-card,
.source-panel {
    position: relative !important;
    overflow: hidden !important;
    border: 1px solid var(--glass-line-strong) !important;
    border-radius: var(--glass-radius) !important;
    background:
        radial-gradient(circle at top right, rgba(56, 189, 248, .14), transparent 18rem),
        linear-gradient(145deg, rgba(255, 255, 255, .15), rgba(255, 255, 255, .07)) !important;
    color: var(--glass-ink) !important;
    box-shadow: var(--glass-shadow-card) !important;
    backdrop-filter: blur(28px) saturate(160%) !important;
    -webkit-backdrop-filter: blur(28px) saturate(160%) !important;
}

.glass-card::before,
.source-panel::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    pointer-events: none !important;
    background: linear-gradient(135deg, rgba(255, 255, 255, .16), transparent 42%) !important;
}

.glass-card > *,
.source-panel > * {
    position: relative !important;
    z-index: 1 !important;
}

.source-panel .bg-slate-900 {
    border: 1px solid rgba(255, 255, 255, .16) !important;
    background: rgba(15, 23, 42, .54) !important;
    color: #e0f2fe !important;
    backdrop-filter: blur(16px) !important;
    -webkit-backdrop-filter: blur(16px) !important;
}

.intro-copy,
.forecast-copy {
    color: #d4deed !important;
}

.intro-copy strong,
.forecast-copy strong {
    color: #ffffff !important;
}

.forecast-rail {
    overflow: visible !important;
    padding: 0 !important;
    margin: 0 !important;
    scrollbar-width: none !important;
}

.forecast-rail::-webkit-scrollbar {
    display: none !important;
}

.forecast-list {
    display: grid !important;
    grid-auto-flow: initial !important;
    grid-auto-columns: initial !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: clamp(16px, 2vw, 24px) !important;
    align-items: stretch !important;
}

.forecast-card {
    position: relative !important;
    min-height: 100% !important;
    overflow: hidden !important;
    border: 1px solid var(--glass-line-strong) !important;
    border-radius: var(--glass-radius) !important;
    background:
        radial-gradient(circle at 90% 0%, rgba(255, 255, 255, .16), transparent 12rem),
        linear-gradient(145deg, rgba(255, 255, 255, .15), rgba(255, 255, 255, .065)) !important;
    color: var(--glass-ink) !important;
    box-shadow: var(--glass-shadow-card) !important;
    scroll-snap-align: unset !important;
    content-visibility: visible !important;
    contain-intrinsic-size: auto !important;
    backdrop-filter: blur(30px) saturate(165%) !important;
    -webkit-backdrop-filter: blur(30px) saturate(165%) !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background .22s ease !important;
}

.forecast-card::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    pointer-events: none !important;
    background:
        linear-gradient(135deg, rgba(255, 255, 255, .18), transparent 42%),
        radial-gradient(circle at 0% 100%, rgba(56, 189, 248, .13), transparent 15rem) !important;
}

.forecast-card::after {
    content: "" !important;
    position: absolute !important;
    left: 22px !important;
    right: 22px !important;
    top: 18px !important;
    height: 3px !important;
    border-radius: 999px !important;
    background: linear-gradient(90deg, rgba(56, 189, 248, .72), rgba(129, 140, 248, .58), rgba(52, 211, 153, .50)) !important;
}

.forecast-card-inner {
    position: relative !important;
    z-index: 1 !important;
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    padding: clamp(18px, 2vw, 26px) !important;
}

.forecast-icon {
    width: 58px !important;
    height: 58px !important;
    border-radius: 20px !important;
    box-shadow:
        0 18px 44px rgba(0, 0, 0, .24),
        inset 0 1px 0 rgba(255, 255, 255, .30) !important;
}

.forecast-card .bg-amber-500,
.forecast-card .bg-sky-500,
.forecast-card .bg-indigo-500,
.forecast-card .bg-cyan-500,
.forecast-card .bg-teal-500,
.forecast-card .bg-slate-500,
.forecast-card .bg-slate-600,
.forecast-card .bg-violet-500 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .42), transparent 42%),
        linear-gradient(135deg, rgba(56, 189, 248, .94), rgba(129, 140, 248, .90)) !important;
    color: #ffffff !important;
}

.forecast-card .bg-orange-50\/90 {
    border: 1px solid rgba(251, 191, 36, .26) !important;
    background: rgba(251, 191, 36, .13) !important;
    color: #fde68a !important;
    backdrop-filter: blur(14px) !important;
    -webkit-backdrop-filter: blur(14px) !important;
}

.forecast-card .bg-amber-50,
.forecast-card .bg-sky-50,
.forecast-card .bg-indigo-50,
.forecast-card .bg-cyan-50,
.forecast-card .bg-teal-50,
.forecast-card .bg-slate-100,
.forecast-card .bg-violet-50,
.forecast-card .bg-emerald-50,
.forecast-card .bg-rose-50,
.forecast-card .bg-orange-50,
.forecast-card .ring-amber-200,
.forecast-card .ring-sky-200,
.forecast-card .ring-indigo-200,
.forecast-card .ring-cyan-200,
.forecast-card .ring-teal-200,
.forecast-card .ring-slate-200,
.forecast-card .ring-violet-200,
.forecast-card .ring-emerald-200,
.forecast-card .ring-rose-200,
.forecast-card .ring-orange-200 {
    border: 1px solid rgba(255, 255, 255, .16) !important;
    background: rgba(255, 255, 255, .09) !important;
    color: #e2e8f0 !important;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, .10) !important;
    --tw-ring-shadow: 0 0 transparent !important;
}

.forecast-title {
    color: #ffffff !important;
    font-size: clamp(28px, 3vw, 42px) !important;
    line-height: .96 !important;
    letter-spacing: -.055em !important;
    text-wrap: balance !important;
}

.forecast-card h3,
.forecast-card .text-slate-950 {
    color: #ffffff !important;
}

.forecast-copy-panel {
    flex-grow: 1 !important;
    border: 1px solid rgba(255, 255, 255, .16) !important;
    border-radius: 26px !important;
    background:
        linear-gradient(145deg, rgba(15, 23, 42, .28), rgba(15, 23, 42, .14)) !important;
    color: #d7e0ee !important;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, .09),
        0 18px 46px rgba(0, 0, 0, .16) !important;
    backdrop-filter: blur(18px) saturate(145%) !important;
    -webkit-backdrop-filter: blur(18px) saturate(145%) !important;
}

.forecast-copy-panel p,
.forecast-copy-panel li {
    color: #d7e0ee !important;
}

.forecast-copy-panel strong {
    color: #ffffff !important;
}

.forecast-toggle {
    border: 1px solid rgba(255, 255, 255, .18) !important;
    border-radius: 18px !important;
    background: rgba(255, 255, 255, .105) !important;
    color: #ffffff !important;
    box-shadow: 0 14px 34px rgba(0, 0, 0, .18) !important;
    backdrop-filter: blur(16px) saturate(145%) !important;
    -webkit-backdrop-filter: blur(16px) saturate(145%) !important;
}

.forecast-toggle-icon {
    background: rgba(255, 255, 255, .14) !important;
    color: #ffffff !important;
}

.footer-surface {
    border-top: 1px solid var(--glass-line) !important;
    background:
        linear-gradient(145deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .045)) !important;
    color: var(--glass-muted) !important;
    backdrop-filter: blur(22px) saturate(150%) !important;
    -webkit-backdrop-filter: blur(22px) saturate(150%) !important;
}

.footer-surface,
.footer-surface span,
.footer-surface .text-slate-600 {
    color: var(--glass-muted) !important;
}

.footer-surface a:not(.btn) {
    color: #ffffff !important;
}

.footer-surface .btn-dark {
    border: 1px solid rgba(255, 255, 255, .18) !important;
    background: rgba(255, 255, 255, .12) !important;
    color: #ffffff !important;
    box-shadow: 0 14px 34px rgba(0, 0, 0, .20) !important;
    backdrop-filter: blur(16px) !important;
    -webkit-backdrop-filter: blur(16px) !important;
}

.footer-surface .btn-dark:hover {
    background: rgba(255, 255, 255, .18) !important;
    color: #ffffff !important;
}

.rounded-\[28px\].border-red-200 {
    border: 1px solid rgba(248, 113, 113, .30) !important;
    background: rgba(127, 29, 29, .32) !important;
    color: #fecaca !important;
    box-shadow: var(--glass-shadow-card) !important;
    backdrop-filter: blur(20px) !important;
    -webkit-backdrop-filter: blur(20px) !important;
}

@media (hover:hover) and (pointer:fine) {
    .forecast-card:hover {
        transform: translateY(-7px) !important;
        border-color: rgba(255, 255, 255, .36) !important;
        background:
            radial-gradient(circle at 90% 0%, rgba(255, 255, 255, .20), transparent 12rem),
            linear-gradient(145deg, rgba(255, 255, 255, .18), rgba(255, 255, 255, .08)) !important;
        box-shadow: 0 38px 94px rgba(0, 0, 0, .38) !important;
    }
}

@media (max-width: 1180px) {
    .forecast-list {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 760px) {
    main.container {
        padding: 10px !important;
    }

    .weather-shell {
        border-radius: 28px !important;
    }

    .hero-surface {
        min-height: auto !important;
        border-radius: 27px 27px 24px 24px !important;
    }

    .hero-pad {
        padding: 22px !important;
    }

    .hero-topbar {
        grid-template-columns: 1fr !important;
        align-items: start !important;
        gap: 18px !important;
    }

    .hero-title {
        font-size: clamp(42px, 15vw, 64px) !important;
    }

    .page-pad {
        padding: 14px !important;
    }

    .forecast-list {
        display: flex !important;
        overflow-x: auto !important;
        gap: 14px !important;
        padding: 2px 2px 12px !important;
        margin: 0 -2px !important;
        scroll-snap-type: x mandatory !important;
        scrollbar-width: none !important;
    }

    .forecast-list::-webkit-scrollbar {
        display: none !important;
    }

    .forecast-card {
        min-width: min(86vw, 360px) !important;
        scroll-snap-align: start !important;
    }

    .forecast-title {
        font-size: 29px !important;
    }

    html.js .forecast-toggle {
        display: inline-flex !important;
    }

    html.js .forecast-card[data-forecast-card]:not(.is-open) .forecast-copy-panel {
        display: none !important;
    }
}

@media (max-width: 420px) {
    .hero-title {
        font-size: 40px !important;
    }

    .forecast-card {
        min-width: 86vw !important;
    }

    .forecast-card-inner {
        padding: 16px !important;
    }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }
}
</style>


<style id="label-color-fix">
.forecast-card::after {
    content: none !important;
    display: none !important;
}

.forecast-card .bg-amber-50 {
    background: #fffbeb !important;
    color: #b45309 !important;
    border-color: #fde68a !important;
}

.forecast-card .bg-sky-50 {
    background: #f0f9ff !important;
    color: #0369a1 !important;
    border-color: #bae6fd !important;
}

.forecast-card .bg-indigo-50 {
    background: #eef2ff !important;
    color: #4338ca !important;
    border-color: #c7d2fe !important;
}

.forecast-card .bg-cyan-50 {
    background: #ecfeff !important;
    color: #0e7490 !important;
    border-color: #a5f3fc !important;
}

.forecast-card .bg-teal-50 {
    background: #f0fdfa !important;
    color: #0f766e !important;
    border-color: #99f6e4 !important;
}

.forecast-card .bg-slate-100 {
    background: #f1f5f9 !important;
    color: #334155 !important;
    border-color: #e2e8f0 !important;
}

.forecast-card .bg-violet-50 {
    background: #f5f3ff !important;
    color: #6d28d9 !important;
    border-color: #ddd6fe !important;
}

.forecast-card .bg-emerald-50 {
    background: #ecfdf5 !important;
    color: #047857 !important;
    border-color: #a7f3d0 !important;
}

.forecast-card .bg-rose-50 {
    background: #fff1f2 !important;
    color: #be123c !important;
    border-color: #fecdd3 !important;
}

.forecast-card .bg-orange-50,
.forecast-card .bg-orange-50\/90 {
    background: #fff7ed !important;
    color: #c2410c !important;
    border-color: #fed7aa !important;
}

.forecast-card .ring-amber-200,
.forecast-card .ring-sky-200,
.forecast-card .ring-indigo-200,
.forecast-card .ring-cyan-200,
.forecast-card .ring-teal-200,
.forecast-card .ring-slate-200,
.forecast-card .ring-violet-200,
.forecast-card .ring-emerald-200,
.forecast-card .ring-rose-200,
.forecast-card .ring-orange-200 {
    box-shadow: inset 0 0 0 1px currentColor !important;
}

.forecast-card .bg-amber-50,
.forecast-card .bg-sky-50,
.forecast-card .bg-indigo-50,
.forecast-card .bg-cyan-50,
.forecast-card .bg-teal-50,
.forecast-card .bg-slate-100,
.forecast-card .bg-violet-50,
.forecast-card .bg-emerald-50,
.forecast-card .bg-rose-50,
.forecast-card .bg-orange-50,
.forecast-card .bg-orange-50\/90 {
    backdrop-filter: none !important;
    -webkit-backdrop-filter: none !important;
}
</style>


<style id="responsive-title-fix">
.hero-title {
    font-size: clamp(38px, 6.2vw, 84px) !important;
    line-height: .92 !important;
    letter-spacing: -.075em !important;
}

.forecast-list {
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
}

.forecast-card {
    min-width: 0 !important;
}

.forecast-card-inner {
    gap: 0 !important;
}

.forecast-title {
    max-width: 100% !important;
    font-size: clamp(29px, 2.45vw, 38px) !important;
    line-height: .96 !important;
    letter-spacing: -.052em !important;
    word-break: normal !important;
    overflow-wrap: anywhere !important;
    hyphens: auto !important;
}

.forecast-date-badge {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    min-height: 28px;
    padding: 6px 10px;
    border: 1px solid rgba(255, 255, 255, .16);
    border-radius: 999px;
    background: rgba(255, 255, 255, .10);
    color: #dbeafe;
    font-size: 12px;
    font-weight: 900;
    line-height: 1;
    letter-spacing: .08em;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, .10);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}

.forecast-copy-panel {
    margin-top: 1.25rem !important;
}

@media (max-width: 1320px) {
    .forecast-list {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .forecast-title {
        font-size: clamp(30px, 3.3vw, 40px) !important;
    }
}

@media (max-width: 900px) {
    .hero-topbar {
        grid-template-columns: 1fr !important;
        align-items: start !important;
    }

    .hero-title {
        font-size: clamp(38px, 10vw, 64px) !important;
    }

    .forecast-list {
        grid-template-columns: 1fr !important;
    }

    .forecast-card {
        width: 100% !important;
    }
}

@media (max-width: 760px) {
    .forecast-list {
        display: flex !important;
        overflow-x: auto !important;
        grid-template-columns: none !important;
    }

    .forecast-card {
        min-width: min(86vw, 360px) !important;
    }

    .forecast-title {
        font-size: 29px !important;
    }
}

@media (max-width: 420px) {
    .hero-title {
        font-size: 36px !important;
    }

    .forecast-title {
        font-size: 26px !important;
    }

    .forecast-date-badge {
        font-size: 11px;
        min-height: 26px;
        padding: 5px 9px;
    }
}
</style>








<style id="favicon-summary-text-fix">
.summary-card .intro-copy,
.summary-card .intro-copy p,
.summary-card .intro-copy li {
    font-weight: 400 !important;
}
</style>


<style id="mobile-scroll-hint-fix">
.mobile-scroll-hint {
    display: none;
}

@media (max-width: 760px) {
    .mobile-scroll-hint {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        margin: 2px 2px 12px;
        color: #e0f2fe;
        font-size: 12px;
        font-weight: 850;
        line-height: 1.2;
        text-align: right;
    }

    .mobile-scroll-hint span:first-child {
        min-height: 34px;
        display: inline-flex;
        align-items: center;
        padding: 8px 12px;
        border: 1px solid rgba(255, 255, 255, .18);
        border-radius: 999px;
        background: rgba(255, 255, 255, .10);
        box-shadow:
            0 12px 30px rgba(0, 0, 0, .18),
            inset 0 1px 0 rgba(255, 255, 255, .14);
        backdrop-filter: blur(16px) saturate(145%);
        -webkit-backdrop-filter: blur(16px) saturate(145%);
    }

    .mobile-scroll-hint-arrow {
        width: 36px;
        height: 36px;
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(255, 255, 255, .22);
        border-radius: 999px;
        color: #ffffff;
        background:
            radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .28), transparent 42%),
            linear-gradient(135deg, rgba(56, 189, 248, .72), rgba(129, 140, 248, .64));
        box-shadow: 0 14px 34px rgba(0, 0, 0, .22);
        animation: scrollHintNudge 1.35s ease-in-out infinite;
    }

    .mobile-scroll-hint-arrow svg {
        width: 18px;
        height: 18px;
    }

    .forecast-rail {
        position: relative !important;
    }

    .forecast-rail::after {
        content: "";
        position: absolute;
        top: 0;
        right: -1px;
        bottom: 12px;
        width: 64px;
        pointer-events: none;
        border-radius: 0 26px 26px 0;
        background: linear-gradient(90deg, transparent, rgba(5, 8, 22, .42));
    }
}

@keyframes scrollHintNudge {
    0%, 100% {
        transform: translateX(0);
    }

    50% {
        transform: translateX(5px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .mobile-scroll-hint-arrow {
        animation: none !important;
    }
}
</style>














<style id="mobile-date-row-fix">
@media (max-width: 760px) {
    .forecast-heading-row {
        flex-direction: row !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 10px !important;
    }

    .forecast-heading-row .forecast-title {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        margin: 0 !important;
        font-size: clamp(28px, 9vw, 38px) !important;
        line-height: .96 !important;
    }

    .forecast-date-badge {
        flex: 0 0 auto !important;
        min-height: 34px !important;
        padding: 7px 11px !important;
        font-size: 12px !important;
        white-space: nowrap !important;
    }
}

@media (max-width: 390px) {
    .forecast-heading-row {
        gap: 8px !important;
    }

    .forecast-heading-row .forecast-title {
        font-size: clamp(26px, 8.6vw, 34px) !important;
    }

    .forecast-date-badge {
        min-height: 31px !important;
        padding: 6px 9px !important;
        font-size: 11px !important;
        letter-spacing: .035em !important;
    }
}
</style>


<style id="icon-theme-color-fix">
.forecast-card .forecast-icon.bg-amber-500 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .42), transparent 42%),
        linear-gradient(135deg, #fbbf24, #f59e0b) !important;
    box-shadow:
        0 18px 44px rgba(245, 158, 11, .28),
        inset 0 1px 0 rgba(255, 255, 255, .30) !important;
}

.forecast-card .forecast-icon.bg-sky-500 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .42), transparent 42%),
        linear-gradient(135deg, #38bdf8, #0284c7) !important;
    box-shadow:
        0 18px 44px rgba(14, 165, 233, .28),
        inset 0 1px 0 rgba(255, 255, 255, .30) !important;
}

.forecast-card .forecast-icon.bg-indigo-500 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .42), transparent 42%),
        linear-gradient(135deg, #818cf8, #4f46e5) !important;
    box-shadow:
        0 18px 44px rgba(99, 102, 241, .30),
        inset 0 1px 0 rgba(255, 255, 255, .30) !important;
}

.forecast-card .forecast-icon.bg-cyan-500 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .42), transparent 42%),
        linear-gradient(135deg, #22d3ee, #0891b2) !important;
    box-shadow:
        0 18px 44px rgba(6, 182, 212, .28),
        inset 0 1px 0 rgba(255, 255, 255, .30) !important;
}

.forecast-card .forecast-icon.bg-teal-500 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .42), transparent 42%),
        linear-gradient(135deg, #2dd4bf, #0d9488) !important;
    box-shadow:
        0 18px 44px rgba(20, 184, 166, .28),
        inset 0 1px 0 rgba(255, 255, 255, .30) !important;
}

.forecast-card .forecast-icon.bg-slate-500,
.forecast-card .forecast-icon.bg-slate-600 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .34), transparent 42%),
        linear-gradient(135deg, #94a3b8, #475569) !important;
    box-shadow:
        0 18px 44px rgba(71, 85, 105, .30),
        inset 0 1px 0 rgba(255, 255, 255, .26) !important;
}

.forecast-card .forecast-icon.bg-violet-500 {
    background:
        radial-gradient(circle at 30% 20%, rgba(255, 255, 255, .42), transparent 42%),
        linear-gradient(135deg, #a78bfa, #7c3aed) !important;
    box-shadow:
        0 18px 44px rgba(124, 58, 237, .30),
        inset 0 1px 0 rgba(255, 255, 255, .30) !important;
}

.forecast-card .forecast-icon svg {
    color: #ffffff !important;
}
</style>






<style id="summary-layout-final-fix">
.summary-row {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) minmax(340px, 420px) !important;
    gap: clamp(14px, 2vw, 24px) !important;
    align-items: stretch !important;
}

.summary-card {
    min-height: 108px !important;
    display: flex !important;
    align-items: center !important;
    padding: clamp(18px, 2vw, 24px) !important;
    border-radius: 30px !important;
    background:
        radial-gradient(circle at 88% 12%, rgba(56, 189, 248, .24), transparent 18rem),
        radial-gradient(circle at 8% 100%, rgba(15, 23, 42, .28), transparent 18rem),
        linear-gradient(145deg, rgba(15, 23, 42, .36), rgba(30, 41, 59, .22)) !important;
    border-color: rgba(255, 255, 255, .26) !important;
}

.summary-card .intro-copy {
    margin: 0 !important;
    max-width: none !important;
    color: #ffffff !important;
    font-size: 15px !important;
    font-weight: 400 !important;
    line-height: 1.55 !important;
    text-shadow: 0 2px 8px rgba(0, 0, 0, .30) !important;
}

.summary-card .intro-copy p,
.summary-card .intro-copy li {
    color: #ffffff !important;
    font-weight: 400 !important;
}

.update-card {
    min-height: 108px !important;
    display: grid !important;
    grid-template-columns: auto minmax(0, 1fr) !important;
    align-items: center !important;
    gap: 16px !important;
    padding: clamp(18px, 2vw, 24px) !important;
    border-radius: 30px !important;
    text-align: left !important;
    background:
        radial-gradient(circle at 82% 10%, rgba(56, 189, 248, .38), transparent 13rem),
        radial-gradient(circle at 8% 100%, rgba(129, 140, 248, .22), transparent 12rem),
        linear-gradient(145deg, rgba(15, 23, 42, .34), rgba(30, 41, 59, .18)) !important;
    border-color: rgba(255, 255, 255, .28) !important;
}

.update-card .bg-slate-900 {
    width: auto !important;
    min-width: 0 !important;
    min-height: 34px !important;
    margin: 0 !important;
    padding: 9px 13px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    white-space: nowrap !important;
    border: 1px solid rgba(255, 255, 255, .24) !important;
    border-radius: 999px !important;
    background:
        linear-gradient(135deg, rgba(15, 23, 42, .78), rgba(30, 41, 59, .62)) !important;
    color: #ffffff !important;
    font-size: 10px !important;
    font-weight: 950 !important;
    letter-spacing: .16em !important;
    text-shadow: 0 1px 4px rgba(0, 0, 0, .42) !important;
    box-shadow:
        0 12px 30px rgba(0, 0, 0, .24),
        inset 0 1px 0 rgba(255, 255, 255, .18) !important;
    backdrop-filter: blur(18px) saturate(150%) !important;
    -webkit-backdrop-filter: blur(18px) saturate(150%) !important;
}

.update-card .bg-slate-900 svg {
    color: #e0f2fe !important;
    opacity: 1 !important;
}

.update-date-value {
    width: auto !important;
    margin: 0 !important;
    color: #ffffff !important;
    font-size: clamp(34px, 3vw, 46px) !important;
    font-weight: 950 !important;
    line-height: .9 !important;
    letter-spacing: -.055em !important;
    text-align: right !important;
    text-shadow: 0 20px 52px rgba(0, 0, 0, .42) !important;
}

@media (max-width: 1120px) {
    .summary-row {
        grid-template-columns: 1fr !important;
    }

    .update-card {
        min-height: 86px !important;
    }

    .summary-card {
        min-height: 86px !important;
    }
}

@media (max-width: 760px) {
    .summary-row {
        gap: 10px !important;
    }

    .summary-card {
        min-height: auto !important;
        padding: 18px 20px !important;
        border-radius: 28px !important;
    }

    .summary-card .intro-copy {
        font-size: 15px !important;
        line-height: 1.55 !important;
    }

    .update-card {
        min-height: auto !important;
        grid-template-columns: auto minmax(0, 1fr) !important;
        gap: 12px !important;
        padding: 14px 16px !important;
        border-radius: 28px !important;
    }

    .update-card .bg-slate-900 {
        padding: 8px 11px !important;
        font-size: 9px !important;
        letter-spacing: .13em !important;
    }

    .update-date-value {
        font-size: clamp(28px, 8.5vw, 38px) !important;
    }
}

@media (max-width: 360px) {
    .update-card {
        grid-template-columns: 1fr !important;
        justify-items: center !important;
        text-align: center !important;
    }

    .update-date-value {
        text-align: center !important;
    }
}
</style>


<style id="update-card-width-fix">
@media (min-width: 1121px) {
    .summary-row {
        grid-template-columns: minmax(0, 1fr) minmax(430px, 500px) !important;
    }

    .update-card {
        grid-template-columns: auto minmax(150px, 1fr) !important;
        padding-inline: clamp(22px, 2.2vw, 32px) !important;
        gap: 20px !important;
    }

    .update-date-value {
        font-size: clamp(34px, 2.55vw, 44px) !important;
        letter-spacing: -.045em !important;
        padding-right: 2px !important;
    }
}

@media (min-width: 1121px) and (max-width: 1320px) {
    .summary-row {
        grid-template-columns: minmax(0, 1fr) minmax(390px, 440px) !important;
    }

    .update-card {
        padding-inline: 22px !important;
        gap: 16px !important;
    }

    .update-date-value {
        font-size: clamp(32px, 2.5vw, 40px) !important;
    }
}
</style>




<style id="forecast-date-stable-place">
.forecast-heading-row {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) auto !important;
    align-items: center !important;
    gap: 12px !important;
    position: static !important;
}

.forecast-heading-row .forecast-title {
    min-width: 0 !important;
    margin: 0 !important;
    padding-right: 0 !important;
}

.forecast-date-badge {
    position: static !important;
    inset: auto !important;
    transform: none !important;
    flex: 0 0 auto !important;
    justify-self: end !important;
    align-self: center !important;
    min-height: 34px !important;
    margin: 0 !important;
    padding: 7px 12px !important;
    border-radius: 999px !important;
    white-space: nowrap !important;
}

@media (max-width: 760px) {
    .forecast-heading-row {
        grid-template-columns: minmax(0, 1fr) auto !important;
        align-items: center !important;
        gap: 10px !important;
    }

    .forecast-heading-row .forecast-title {
        padding-right: 0 !important;
        font-size: clamp(28px, 8.6vw, 36px) !important;
        line-height: .96 !important;
    }

    .forecast-date-badge {
        min-height: 33px !important;
        padding: 7px 10px !important;
        font-size: 12px !important;
        letter-spacing: .045em !important;
    }
}

@media (max-width: 390px) {
    .forecast-heading-row {
        gap: 8px !important;
    }

    .forecast-heading-row .forecast-title {
        font-size: clamp(25px, 8vw, 32px) !important;
    }

    .forecast-date-badge {
        min-height: 30px !important;
        padding: 6px 8px !important;
        font-size: 10.5px !important;
        letter-spacing: .03em !important;
    }
}
</style>


<style id="mobile-update-spacing-fix">
@media (max-width: 760px) {
    .update-card {
        grid-template-columns: auto auto !important;
        justify-content: center !important;
        justify-items: center !important;
        align-items: center !important;
        gap: 12px !important;
        padding-inline: 18px !important;
    }

    .update-card .bg-slate-900 {
        justify-self: center !important;
        margin: 0 !important;
    }

    .update-date-value {
        width: auto !important;
        justify-self: center !important;
        text-align: center !important;
        font-size: clamp(26px, 7.7vw, 34px) !important;
        padding: 0 !important;
        margin: 0 !important;
        letter-spacing: -.045em !important;
    }
}

@media (max-width: 390px) {
    .update-card {
        gap: 10px !important;
        padding-inline: 16px !important;
    }

    .update-date-value {
        font-size: clamp(24px, 7.2vw, 31px) !important;
    }
}

@media (max-width: 350px) {
    .update-card {
        grid-template-columns: 1fr !important;
        gap: 9px !important;
    }

    .update-date-value {
        width: 100% !important;
    }
}
</style>


<style id="mobile-card-shadow-remove-final">
@media (max-width: 760px) {
    .page-pad > section,
    .forecast-rail,
    .forecast-list {
        background: transparent !important;
        background-color: transparent !important;
        background-image: none !important;
        border: 0 !important;
        outline: 0 !important;
        box-shadow: none !important;
        filter: none !important;
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
    }

    .forecast-rail {
        overflow-x: auto !important;
        overflow-y: visible !important;
        margin: 0 !important;
        padding: 0 0 18px !important;
        border-radius: 0 !important;
    }

    .forecast-list {
        display: flex !important;
        gap: 14px !important;
        margin: 0 !important;
        padding: 0 2px 18px !important;
        border-radius: 0 !important;
    }

    .forecast-rail::before,
    .forecast-rail::after,
    .forecast-list::before,
    .forecast-list::after {
        content: none !important;
        display: none !important;
    }

    .forecast-card,
    .forecast-card:hover,
    .forecast-card:active,
    .forecast-card:focus-within {
        min-width: min(86vw, 360px) !important;
        margin: 0 !important;
        border-radius: 32px !important;
        transform: none !important;
        filter: none !important;
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, .16),
            inset 0 -1px 0 rgba(255, 255, 255, .08) !important;
    }

    .forecast-card::before {
        border-radius: inherit !important;
        box-shadow: none !important;
        filter: none !important;
    }

    .forecast-card-inner {
        border-radius: inherit !important;
        box-shadow: none !important;
        filter: none !important;
    }
}

@media (max-width: 420px) {
    .forecast-card,
    .forecast-card:hover,
    .forecast-card:active,
    .forecast-card:focus-within {
        min-width: 86vw !important;
        border-radius: 32px !important;
    }
}
</style>




<style id="summary-compact-alignment-final">
@media (min-width: 761px) {
    .summary-row {
        align-items: stretch !important;
        gap: clamp(14px, 2vw, 22px) !important;
    }

    .summary-card,
    .update-card {
        min-height: 0 !important;
        height: auto !important;
        padding-block: 13px !important;
        padding-inline: clamp(18px, 2vw, 24px) !important;
        border-radius: 28px !important;
    }

    .summary-card {
        display: flex !important;
        align-items: center !important;
    }

    .summary-card .intro-copy {
        margin: 0 !important;
        font-size: 15px !important;
        line-height: 1.45 !important;
    }

    .update-card {
        display: grid !important;
        grid-template-columns: auto auto !important;
        justify-content: center !important;
        justify-items: center !important;
        align-items: center !important;
        gap: clamp(14px, 1.8vw, 22px) !important;
    }

    .update-card .bg-slate-900 {
        align-self: center !important;
        justify-self: center !important;
        min-height: 34px !important;
        height: 34px !important;
        margin: 0 !important;
        padding: 0 13px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1 !important;
        transform: none !important;
    }

    .update-date-value {
        align-self: center !important;
        justify-self: center !important;
        width: auto !important;
        min-height: 34px !important;
        height: 34px !important;
        margin: 0 !important;
        padding: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        line-height: 34px !important;
        font-size: clamp(32px, 2.65vw, 42px) !important;
        transform: translateY(-3px) !important;
    }
}

@media (min-width: 1121px) {
    .summary-row {
        grid-template-columns: minmax(0, 1fr) minmax(410px, 470px) !important;
    }
}

@media (min-width: 1121px) and (max-width: 1320px) {
    .summary-row {
        grid-template-columns: minmax(0, 1fr) minmax(380px, 430px) !important;
    }

    .update-date-value {
        font-size: clamp(30px, 2.45vw, 38px) !important;
    }
}

@media (max-width: 760px) {
    .summary-card,
    .update-card {
        min-height: 0 !important;
    }
}
</style>






<style id="desktop-page-center-final">
@media (min-width: 761px) {
    html {
        min-height: 100%;
    }

    body {
        min-height: 100vh !important;
        min-height: 100svh !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    main.container {
        width: 100% !important;
        margin: 0 auto !important;
    }

    .weather-shell {
        width: 100% !important;
        margin: 0 auto !important;
    }
}
</style>

<style id="forecast-title-autofit-style">
.forecast-heading-row .forecast-title[data-forecast-title] {
    max-width: 100% !important;
    white-space: nowrap !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
    hyphens: manual !important;
}
</style>

</head>
<body class="min-h-screen text-slate-800 antialiased">
<main class="container py-4 py-lg-5"><section class="weather-shell">
<div class="page-pad">
<?php if($errorMessage!==null): ?>
<div class="rounded-[28px] border border-red-200 bg-red-50 p-4 text-red-800 shadow-card" role="alert"><div class="flex gap-3"><div class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-red-100 text-red-700"><?php echo renderForecastIcon('storm','h-5 w-5'); ?></div><div><h2 class="mb-2 text-sm font-black uppercase tracking-[0.22em] text-red-700">Błąd pobierania</h2><p class="mb-0 text-sm leading-6"><?php echo escape($errorMessage); ?></p></div></div></div>
<?php else: ?>
<?php if($hasIntro): ?>
<div class="summary-row mb-4">
    <section class="glass-card source-panel summary-card rounded-[30px] p-4 sm:p-5">
        <div class="intro-copy mt-4 text-slate-700"><?php echo getForecastDisplayIntroHtml($intro); ?></div>
    </section>

    <aside class="glass-card update-card rounded-[30px] p-4 sm:p-5" aria-label="Data aktualizacji prognozy">
        <div class="inline-flex items-center gap-2 rounded-full bg-slate-900 px-3 py-2 text-[11px] font-black uppercase tracking-[0.24em] text-white"><?php echo renderForecastIcon('trend-stable','h-4 w-4'); ?> Aktualizacja</div>
        <div class="update-date-value"><?php echo escape($dateDisplay); ?></div>
    </aside>
</div>
<?php endif; ?>
<section>
<div class="mobile-scroll-hint" aria-hidden="true">
    <span>Przesuń w prawo, aby zobaczyć kolejne dni</span>
    <span class="mobile-scroll-hint-arrow">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5"/>
        </svg>
    </span>
</div>
<div class="forecast-rail" aria-label="Pozioma lista prognoz"><div class="forecast-list">
<?php foreach($forecastDays as$index=>$day): ?>
<?php $dayContentId='day-note-'.(int)$index;$temperatureBadge=extractTemperatureBadge(strip_tags($day['content']));if($temperatureBadge['value']===''&&isset($day['temperature'])&&$day['temperature']!=='')$temperatureBadge=['value'=>(string)$day['temperature'],'label'=>'maks.'];$displaySignals=getForecastDisplaySignals($day);$displayTitle=html_entity_decode((string)$day['title'],ENT_QUOTES|ENT_HTML5,'UTF-8');$displayDate='';$titleNote='';if(preg_match('/^(.*?)\s*\[([^\]]+)\]\s*(?:[–\-:]\s*(.+))?$/u',$displayTitle,$titleParts)===1){$displayTitle=trim($titleParts[1]);$displayDate=trim($titleParts[2]);$titleNote=isset($titleParts[3])?trim($titleParts[3]):'';} ?>
<article id="day-<?php echo(int)$index; ?>" class="forecast-card border <?php echo $day['theme']['surface_classes']; ?>" data-forecast-card>
<div class="forecast-card-inner p-4 sm:p-5">
<div class="flex items-start justify-between gap-3"><div class="forecast-icon inline-flex items-center justify-center <?php echo $day['theme']['panel_classes']; ?> shadow-lg"><?php echo renderForecastIcon($day['theme']['icon'],'h-7 w-7'); ?></div>
<?php if($temperatureBadge['value']!==''): ?><div class="inline-flex min-h-[2.65rem] shrink-0 items-center gap-2 rounded-2xl border border-orange-200 bg-orange-50/90 px-3 py-2 text-sm font-black text-orange-700 shadow-sm"><?php echo renderForecastIcon('temp-high','h-4 w-4'); ?><?php echo escape(trim($temperatureBadge['label'].' '.$temperatureBadge['value'])); ?></div><?php endif; ?></div>
<div class="mt-4">
<span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.20em] <?php echo $day['theme']['chip_classes']; ?>"><?php echo escape($day['theme']['label']); ?></span>
<div class="forecast-heading-row mt-3">
    <h3 class="forecast-title text-2xl font-black uppercase text-slate-950 sm:text-[2rem]" data-forecast-title><?php echo escape($displayTitle); ?></h3>
    <?php if($displayDate!==''): ?><div class="forecast-date-badge"><?php echo escape($displayDate); ?></div><?php endif; ?>
</div>
</div>
<?php if($displaySignals!==[]): ?><div class="mt-4 flex flex-wrap gap-2"><?php foreach($displaySignals as$signal): ?><span class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-[12px] font-bold <?php echo $signal['classes']; ?> shadow-sm"><?php echo renderForecastIcon($signal['icon'],'h-4 w-4'); ?><?php echo escape($signal['label']); ?></span><?php endforeach; ?></div><?php endif; ?>
<button type="button" class="forecast-toggle mt-4" data-forecast-toggle data-label-open="Ukryj opis" data-label-closed="Pokaż opis" aria-controls="<?php echo escape($dayContentId); ?>" aria-expanded="false"><span data-forecast-toggle-label>Pokaż opis</span><span class="forecast-toggle-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" /></svg></span></button>
<div id="<?php echo escape($dayContentId); ?>" class="forecast-copy forecast-copy-panel mt-4 grow rounded-[24px] border border-white/80 bg-white/76 px-4 py-4 text-slate-700 shadow-inner shadow-slate-100/70 sm:px-5"><?php $displayContent=$day['content'];if($titleNote!=='')$displayContent=preg_replace('/^(\s*<p\b[^>]*>)/iu','$1<strong>'.escape($titleNote).'</strong> – ',$displayContent,1);echo capitalizeFirstVisibleLetterInHtml($displayContent); ?></div>
</div></article>
<?php endforeach; ?>
</div></div></section>
<?php endif; ?>
</div>
<footer class="footer-surface px-4 py-4 px-sm-5"><div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 text-sm text-slate-600"><div class="d-flex flex-wrap align-items-center gap-2"><span>Dane pochodzą z serwisu</span><a class="fw-bold text-slate-950 text-decoration-none" href="https://pogodadlaslaska.pl" target="_blank" rel="noopener noreferrer">pogodadlaslaska.pl</a></div><div class="d-flex flex-column flex-sm-row gap-2"><a class="btn btn-sm btn-dark rounded-pill px-3 fw-bold" href="https://buycoffee.to/prognoza-pogody" target="_blank" rel="noopener noreferrer">Wesprzyj autora prognozy</a></div></div></footer>
</section></main>
<script>
document.addEventListener('DOMContentLoaded',function(){var mobileQuery=window.matchMedia('(max-width: 575.98px)'),cards=document.querySelectorAll('[data-forecast-card]');
function applyToggleState(card,toggle){var label=toggle.querySelector('[data-forecast-toggle-label]'),isOpen=mobileQuery.matches&&card.classList.contains('is-open');if(!mobileQuery.matches)card.classList.remove('is-open');toggle.setAttribute('aria-expanded',isOpen?'true':'false');if(label)label.textContent=isOpen?(toggle.getAttribute('data-label-open')||'Ukryj opis'):(toggle.getAttribute('data-label-closed')||'Pokaż opis');}
function syncToggleState(){cards.forEach(function(card){var toggle=card.querySelector('[data-forecast-toggle]');if(toggle)applyToggleState(card,toggle);});}
cards.forEach(function(card){var toggle=card.querySelector('[data-forecast-toggle]');if(!toggle)return;toggle.addEventListener('click',function(){if(!mobileQuery.matches)return;card.classList.toggle('is-open');applyToggleState(card,toggle);});});
if(typeof mobileQuery.addEventListener==='function')mobileQuery.addEventListener('change',syncToggleState);else if(typeof mobileQuery.addListener==='function')mobileQuery.addListener(syncToggleState);syncToggleState();});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var titles = Array.prototype.slice.call(document.querySelectorAll('[data-forecast-title]'));
    if (!titles.length) return;

    var minFontSize = 18;
    var resizeTimer = null;

    function titleFits(title, availableWidth) {
        return title.scrollWidth <= availableWidth + 1;
    }

    function fitForecastTitle(title) {
        title.style.removeProperty('font-size');
        title.style.setProperty('white-space', 'nowrap', 'important');
        title.style.setProperty('word-break', 'normal', 'important');
        title.style.setProperty('overflow-wrap', 'normal', 'important');
        title.style.setProperty('hyphens', 'manual', 'important');

        var availableWidth = title.clientWidth;
        if (!availableWidth) return;

        var baseFontSize = parseFloat(window.getComputedStyle(title).fontSize);
        if (!baseFontSize) return;

        title.style.setProperty('font-size', baseFontSize + 'px', 'important');
        if (titleFits(title, availableWidth)) {
            title.removeAttribute('data-forecast-title-fitted');
            return;
        }

        var low = minFontSize;
        var high = baseFontSize;
        for (var i = 0; i < 10; i += 1) {
            var middle = (low + high) / 2;
            title.style.setProperty('font-size', middle + 'px', 'important');
            if (titleFits(title, availableWidth)) low = middle;
            else high = middle;
        }

        title.style.setProperty('font-size', Math.max(minFontSize, Math.floor(low * 10) / 10) + 'px', 'important');
        title.setAttribute('data-forecast-title-fitted', 'true');
    }

    function fitAllForecastTitles() {
        titles.forEach(fitForecastTitle);
    }

    function scheduleFit() {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
            window.requestAnimationFrame(fitAllForecastTitles);
        }, 80);
    }

    window.requestAnimationFrame(fitAllForecastTitles);
    window.addEventListener('resize', scheduleFit, { passive: true });
    window.addEventListener('orientationchange', scheduleFit, { passive: true });

    if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
        document.fonts.ready.then(fitAllForecastTitles);
    }

    if ('ResizeObserver' in window) {
        var observer = new ResizeObserver(scheduleFit);
        titles.forEach(function (title) {
            if (title.parentElement) observer.observe(title.parentElement);
        });
    }
});
</script>
</body>
</html>
