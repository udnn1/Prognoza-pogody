<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../index.php');
if($source===false){
    fwrite(STDERR,'Nie udało się odczytać index.php.'.PHP_EOL);
    exit(1);
}

$marker='$errorMessage=null;';
$bootstrapStart=strpos($source,$marker);
if($bootstrapStart===false){
    fwrite(STDERR,'Nie znaleziono granicy części wykonawczej w index.php.'.PHP_EOL);
    exit(1);
}

$functionsSource=substr($source,0,$bootstrapStart);
$functionsSource=preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/','',$functionsSource);
if(!is_string($functionsSource)){
    fwrite(STDERR,'Nie udało się przygotować kodu funkcji.'.PHP_EOL);
    exit(1);
}

eval($functionsSource);

function expectSameValue($expected,$actual,$message):void{
    if($expected===$actual)return;
    fwrite(STDERR,$message.' Oczekiwano: '.var_export($expected,true).', otrzymano: '.var_export($actual,true).PHP_EOL);
    exit(1);
}

function expectMissingLabel($signals,$label):void{
    foreach($signals as$signal){
        if(($signal['label']??'')===$label){
            fwrite(STDERR,'Nieoczekiwany sygnał: '.$label.PHP_EOL);
            exit(1);
        }
    }
}

$cases=[
    'polskie znaki'=>'Pogodę w naszym regionie będzie kształtować front atmosferyczny rozciągający się od Małopolski i częściowo Górnego Śląska po Warmię, dlatego też zachmurzenie będzie całkowite i w większości regionu wystąpią okresami opady deszczu Jako ciekawostkę dodam, że w tym samym czasie np. na wschodzie Polski i nad morzem będzie sucho i słonecznie Nadal chłodno. Temp. max. 9/12°C. Wiatr słaby i umiarkowany z północnego zachodu i północy. Ciśnienie będzie wolno rosnąć do ok. 1013 hPa.',
    'bez polskich znaków'=>'Pogode w naszym regionie bedzie ksztaltowac front atmosferyczny rozciagajacy sie od Malopolski i czesciowo Gornego Slaska po Warmie, dlatego tez zachmurzenie bedzie calkowite i w wiekszosci regionu wystapia okresami opady deszczu Jako ciekawostke dodam, ze w tym samym czasie np. na wschodzie Polski i nad morzem bedzie sucho i slonecznie Nadal chlodno. Temp. max. 9/12 C. Wiatr slaby i umiarkowany z polnocnego zachodu i polnocy. Cisnienie bedzie wolno rosnac do ok. 1013 hPa.'
];

foreach($cases as$name=>$sundayNote){
    $theme=getForecastTheme($sundayNote);
    $signals=getForecastSignals($sundayNote,$theme);

    expectSameValue('rain',$theme['icon'],'Niedzielna notatka z lokalnymi opadami nie może dostać motywu słonecznego ('.$name.').');
    expectSameValue('Opady',$theme['label'],'Niedzielna notatka powinna dostać etykietę opadów ('.$name.').');
    expectMissingLabel($signals,'Słonecznie');
    expectMissingLabel($signals,'Trend: poprawa');
}

echo'OK'.PHP_EOL;
