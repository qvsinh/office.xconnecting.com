<?php

use CodeIgniter\CodingStandard\CodeIgniter4;
use Nexus\CsConfig\Factory;
$finder = (new PhpCsFixer\Finder())
	->in([
		__DIR__ . '/app/Controllers',
		__DIR__ . '/app/Models',
		__DIR__ . '/app/Views',
		__DIR__ . '/app/Libraries',
		__DIR__ . '/app/Helpers',
		__DIR__ . '/app/Filters',
	])
	->name('*.php')
	->ignoreDotFiles(true)
	->ignoreVCS(true);

$arrRules = [
	'strict_comparison' => false
];
return Factory::create(new CodeIgniter4(), $arrRules, ['finder' => $finder])->forProjects();



