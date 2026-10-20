<?php
// Used only on the isolated documentation source copy; never formats live code.
require $argv[1].'/vendor/autoload.php';
$parser=(new PhpParser\ParserFactory)->createForNewestSupportedVersion();
$printer=new PhpParser\PrettyPrinter\Standard;
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1].'/app/Http/Controllers'));
foreach($files as $file) if($file->isFile() && $file->getExtension()==='php') file_put_contents($file->getPathname(),$printer->prettyPrintFile($parser->parse(file_get_contents($file->getPathname()))));
