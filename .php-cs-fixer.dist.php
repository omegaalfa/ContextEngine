<?php
declare(strict_types=1);
use PhpCsFixer\Config;
use PhpCsFixer\Finder;
return (new Config())->setRiskyAllowed(true)->setRules(['@PSR12'=>true,'@PHP84Migration'=>true,'declare_strict_types'=>true,'ordered_imports'=>true,'single_line_empty_body'=>true])->setFinder((new Finder())->in([__DIR__.'/src',__DIR__.'/tests']));
