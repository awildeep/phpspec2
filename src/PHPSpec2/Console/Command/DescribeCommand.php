<?php

namespace PHPSpec2\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use PHPSpec2\Console;

class DescribeCommand extends Command
{
    /**
     * Initializes command.
     */
    public function __construct()
    {
        parent::__construct('describe');

        $this->setAliases(array('desc','describe'));
        $this->setName('describe');
        $this->setHelp('Describe - This command will build a new spec file for you (if it does not exist), as well as allow you to add new examples to an existing spec.');

        $this->setDefinition(array(
            new InputArgument('spec', InputArgument::REQUIRED, 'Spec to describe'),
            new InputArgument('example', InputArgument::OPTIONAL, 'Example to implement', ''),
            new InputOption('src-path', null, InputOption::VALUE_REQUIRED, 'Source path', 'src'),
            new InputOption('spec-path', null, InputOption::VALUE_REQUIRED, 'Specs path', 'spec'),
            new InputOption('namespace', null, InputOption::VALUE_REQUIRED, 'Specs NS', 'spec\\'),
        ));
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->setFormatter(new Console\Formatter($output->isDecorated()));

        $this->io = new Console\IO($input, $output, $this->getHelperSet());
        $spec     = $input->getArgument('spec');
        $example   = $input->getArgument('example');

        if (!is_dir($specsPath = $input->getOption('spec-path'))) {
            mkdir($specsPath, 0777, true);
        }

        $spec = preg_replace('#^'.preg_quote($specsPath, '#').'/#', '', $spec);
        if ($srcPath = $input->getOption('src-path')) {
            $spec = preg_replace('#^'.preg_quote($srcPath, '#').'/#', '', $spec);
        }
        $spec = preg_replace('#\.php$#', '', $spec);
        $spec = str_replace('/', '\\', $spec);

        $specsPath = realpath($specsPath).DIRECTORY_SEPARATOR;
        $subject   = str_replace('/', '\\', $spec);
        $classname = $input->getOption('namespace').$subject;
        $filepath  = $specsPath.str_replace('\\', DIRECTORY_SEPARATOR, $spec).'.php';
        $namespace = str_replace('/', '\\', dirname(str_replace('\\', DIRECTORY_SEPARATOR, $classname)));
        $class     = basename(str_replace('\\', DIRECTORY_SEPARATOR, $classname));

        if ($example != '') {
            if (!file_exists($filepath)) {
                $this->writeSpec ($filepath, $classname, $namespace, $class, $subject, $output);
            }
            return $this->writeExample ($filepath, $example, $class, $output);
        } else {
            return $this->writeSpec ($filepath, $classname, $namespace, $class, $subject, $output);
        }
    }
    
    protected function checkForUseStatement ($fileContent, $filepath) 
    {
        //find if the use statement 'use PHPSpec2\Exception\Example;' is in our spec class.
        $useStatement = 'use PHPSpec2\Exception\Example\PendingException;';
        $useExceptionPos = strrpos ($fileContent, $useStatement);
        if($useExceptionPos === false) {
            $this->io->writeln(sprintf(
                '<info>Unable to find required use statement <value>"%s"</value> in <value>"%s"</value>.</info>', 
                $useStatement,
                $filepath
            ), false);

            $insertUse = $this->io->askConfirmation(sprintf(
                'Would you like to try and insert it?', basename($filepath)
            ), false);

            if (!$insertUse) {
                $this->io->writeln(sprintf(
                    '<info>Unable to find required use statement in <value>"%s"</value>.  Add <value>"%s"</value> to the top of your spec class.</info>', 
                    $filepath,
                    $useStatement
                    ), false);

                return false;
            } else {
                $lastUseLocation = strrpos($fileContent, 'use');
                
                // var_dump ($lastUseLocation);
                if($lastUseLocation) {
                    $fileContent = substr_replace($fileContent, $useStatement."\nuse", $lastUseLocation, 3);
                } else {
                    $this->io->writeln(sprintf(
                        '<info>Unable to to add use statement in <value>"%s"</value>.  Add <value>"%s"</value> to the top of your spec class.</info>', 
                        $filepath,
                        $useStatement
                        ), false);

                    return false;
                }
            }
        }
        
        return $fileContent;
    }
    
    protected function writeExample ($filepath, $method, $class) 
    {
        $fileContent = file_get_contents($filepath);
        
        $fileContent = $this->checkForUseStatement($fileContent, $filepath);
        if ($fileContent === false) {
            return 1;
        }
        
        
        //Find the currently defined matching function name to help the user in a situation of CASE mismatching
        $currentFunctionLocation = stripos($fileContent, $this->getMethodEscapedNameFor($method));
        if($currentFunctionLocation) {
            $currentFunctionName = substr($fileContent, $currentFunctionLocation, strlen($this->getMethodEscapedNameFor($method)));
            $this->io->writeln(sprintf('<info>Method <value>"%s"</value> already exists as <value>"%s"</value>; nothing changed.</info>',
                $this->getMethodEscapedNameFor($method),
                $currentFunctionName
            ));
            
            return 1;
        }
        
        $methodContent = $this->getExampleContentFor(array(
            '%method%' =>$this->getMethodEscapedNameFor($method)
        ));
        
        if (!$fp = fopen($filepath, 'w')) {
             $this->io->writeln(sprintf(
                 '<info>File <value>"%s"</value> is not writable.</info>', $filepath
             ), false);

             return 1;
        }

        //Find the last closing curly brace position
        $lastCloseCurlyPos = strrpos ($fileContent, '}');
        if($lastCloseCurlyPos === false) {
            $this->io->writeln(sprintf(
                 '<info>Unable to find the last closing curly brace "}" in <value>"%s"</value>.</info>', $filepath
             ), false);
             
             return 1;
        }
        
        $closeContent = "\n}";
        
        $fileContent = substr_replace($fileContent, $methodContent.$closeContent, $lastCloseCurlyPos, 1);
        fwrite($fp, $fileContent);
        fclose($fp);
        
        $this->io->writeln(sprintf("<info>Method <value>%s</value> created in <value>%s</value>.</info>\n",
            $this->getMethodEscapedNameFor($method), $this->relativizePath($filepath)
        ));
    }
    
    protected function writeSpec ($filepath, $classname, $namespace, $class, $subject) 
    {
        if (file_exists($filepath)) {
            $overwrite = $this->io->askConfirmation(sprintf(
                'File "%s" already exists. Overwrite?', basename($filepath)
            ), false);

            if (!$overwrite) {
                return 1;
            }

            $this->io->writeln();
        }
                
        $dirpath = dirname($filepath);
        if (!is_dir($dirpath)) {
            mkdir($dirpath, 0777, true);
        }
        file_put_contents($filepath, $this->getSpecContentFor(array(
            '%classname%' => $classname,
            '%namespace%' => $namespace,
            '%filepath%'  => $filepath,
            '%class%'     => $class,
            '%subject%'   => $subject
        )));

        $this->io->writeln(sprintf("<info>Specification for <value>%s</value> created in <value>%s</value>.</info>\n",
            $subject, $this->relativizePath($filepath)
        ));
    }

    protected function getMethodEscapedNameFor($inputMethodName)
    {
        //detect 'its ', 'its_', 'it ', or 'it_' prefix
        preg_match('/^(it[s|\s|\_])/i', $inputMethodName, $matches);
        //If no matches are found, assume 'it_' as the default.
        if (count($matches) == 0) {
            $inputMethodName = 'it_'.$inputMethodName;
        }
        
        return str_replace(' ', '_', $inputMethodName);
    }

    protected function getExampleContentFor(array $parameters)
    {
        $template = file_get_contents(__DIR__.'/../../Resources/templates/example.php');

        return strtr($template, $parameters);
    }

    protected function getSpecContentFor(array $parameters)
    {
        $template = file_get_contents(__DIR__.'/../../Resources/templates/spec.php');

        return strtr($template, $parameters);
    }

    private function relativizePath($filepath)
    {
        return str_replace(getcwd().DIRECTORY_SEPARATOR, '', $filepath);
    }
}
