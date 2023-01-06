<?php

error_reporting(E_ALL);

require_once 'vendor/autoload.php';
use LanguageDetection\Language;

$languages_to_detect = [];

$ld = new Language($languages_to_detect);						

$strings = array(
'ਈਕੋਕ੍ਰਿਟੀਸਿਜ਼ਮ ਦੇ ਸੰਦਰਭ ਵਿੱਚ ਭਾਈ ਵੀਰ ਸਿੰਘ ਦੀ ਕਵਿਤਾ',
'ਸੈਕੰਡਰੀ ਸਿੱਖਿਆ ਪ੍ਰਣਾਲੀ ਦੇ ਸੰਦਰਭ ਵਿੱਚ ਪੰਜਾਬੀ ਭਾਸ਼ਾ ਤੇ ਵਿਆਕਰਣ ਸਬੰਧੀ ਗਿਆਨ ਦਾ ਅਧਿਐਨ',
'Hello there',
'광양만의 부채발갯지렁이류',
'大果蠅（Drosophila immigrans）自然族群之粒線體DNA之變異',
'臺灣產彈塗魚（鱸目，鰕虎科）之遺傳變異',
);

foreach ($strings as $str)
{
	echo $str . "\n";
	$language = $ld->detect($str);
	
	//print_r($language);
	
	echo $language->__toString() . "\n";


}

?>
