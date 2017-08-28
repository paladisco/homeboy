<?php

namespace App\Commands;

use App\Configuration\Config;
use App\FileManagers\HomesteadFileManager;
use App\FileManagers\HostsFileManager;
use App\Input\Interrogator;
use App\Support\Vagrant\Vagrant;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Host extends Command
{

    private $questionHelper;
    private $inputInterface;
    private $outputInterface;

    private $config;

    private $name;
    private $composerProject;
    private $folder;
    private $folderSuffix;
    private $database;
    private $domain;
    private $useDefaults=false;
    private $skipConfirmation=false;
	private $noCreate=false;

    private $interrogator;

    private $vagrant;

    protected function configure()
    {
        $this
            ->setName('host')
            ->setDescription('Host a new site')
            ->setHelp("")
            ->addCommandOptions();
    }

    private function init(InputInterface $input, OutputInterface $output){
        $this->inputInterface = $input;
        $this->outputInterface = $output;
        $this->questionHelper = $this->getHelper('question');
        $this->interrogator = new Interrogator($input, $output, $this->getHelper('question'));
        $this->config = new Config();
        $vagrantAccessDirectoryCommand = 'cd '.$this->config->getHomesteadBoxPath();
        if(!empty($this->config->getHomesteadAccessDirectoryCommand())){
            $vagrantAccessDirectoryCommand = $this->config->getHomesteadAccessDirectoryCommand();
        }
        $this->vagrant = new Vagrant($vagrantAccessDirectoryCommand);
    }

    private function addCommandOptions(){
        $this->addOption(
            'use-defaults',
            null,
            InputOption::VALUE_NONE,
            'Ignore questions and use defaults'
        );
        $this->addOption(
            'skip-confirmation',
            null,
            InputOption::VALUE_NONE,
            'Skip Confirmation'
        );
        $this->addOption(
            'name',
            null,
            InputOption::VALUE_REQUIRED,
            'Project Name',
            null
        );
        $this->addOption(
            'database',
            null,
            InputOption::VALUE_REQUIRED,
            'Database',
            'homestead'
        );
        $this->addOption(
            'domain',
            null,
            InputOption::VALUE_REQUIRED,
            'Development Domain',
            null
        );
        $this->addOption(
            'no-create',
            null,
            InputOption::VALUE_NONE,
            'Do not create project, use existing repo'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->init($input, $output);
        $this->updateFromOptions();
        $this->interrogate();

        if($this->skipConfirmation){
            $taskConfirmation = true;
        }else{
            $taskConfirmation = $this->getTaskConfirmationFromQuestion();
        }

        if($taskConfirmation){
            $this->runTasks();
        }else{
            $output->writeln('<error>Tasks cancelled</error>');
        }

        return;

    }

    private function updateFromOptions(){
        if($this->inputInterface->getOption('use-defaults')){
            $this->useDefaults = boolval($this->inputInterface->getOption('use-defaults'));
        }
        if($this->inputInterface->getOption('skip-confirmation')){
            $this->skipConfirmation = boolval($this->inputInterface->getOption('skip-confirmation'));
        }
        if($this->inputInterface->getOption('no-create')){
            $this->noCreate = boolval($this->inputInterface->getOption('no-create'));
        }
        if($this->inputInterface->getOption('name')){
            $this->name = $this->inputInterface->getOption('name');
        }
        if($this->inputInterface->getOption('database')){
            $this->database = $this->inputInterface->getOption('database');
        }else{
            $this->database = $this->defaultDatabaseNameFromKey($this->name);
        }
        if($this->inputInterface->getOption('domain')){
            $this->domain = $this->inputInterface->getOption('domain');
        }else{
            $this->domain = $this->defaultDomainNameFromKey($this->name);
        }
    }

    private function interrogate(){
        if(!$this->useDefaults) {

            if(is_null($this->name)){
                $this->name = $this->interrogator->ask(
                    'What is your project\'s name?',
                    'project-' . time()
                );
            }

            if ($this->config->getUseComposer()) {
                $this->composerProject = $this->config->getComposerProject();
            }

            $this->folder = $this->config->getFolder();

            if ($this->composerProject != 'laravel/laravel') {
                $this->folderSuffix = $this->interrogator->ask(
                    'Point site to?',
                    $this->config->getFolderSuffix()
                );
            }

            if(!$this->inputInterface->getOption('database')) {
                $this->database = $this->defaultDatabaseNameFromKey($this->name);
                $this->database = $this->interrogator->ask(
                    'Database Name?',
                    $this->database
                );
            }

            $this->domain = $this->defaultDomainNameFromKey($this->name);

        }
    }

    private function getTaskConfirmationFromQuestion(){
        $this->outputInterface->writeln('<info>The following tasks will be executed:</info>');
        if($this->config->getUseComposer() && !empty($this->composerProject)){
            $this->outputInterface->writeln("- Run Command: cd {$this->folder} && git clone {$this->composerProject} {$this->name}");
        }
        $this->outputInterface->writeln('- ('.$this->config->getHostsPath().') add line: '.$this->config->getHostIP().' '.$this->domain);
        $this->outputInterface->writeln('- ('.$this->config->getHomesteadPath().') map : '.$this->domain.' to '.$this->config->getHomesteadSitesPath().$this->name.$this->folderSuffix);
        $this->outputInterface->writeln('- ('.$this->config->getHomesteadPath().') add to databases: '.$this->database);
        if(!empty($this->homesteadProvisionCommand)){
            $this->outputInterface->writeln('- Run Command: '.$this->homesteadProvisionCommand);
        }else{
            $this->outputInterface->writeln('- Run Command: homesteadp');
        }

        // $response = $this->interrogator->ask(
        //     'Run tasks?',
        //     'Y'
        // );
        // if(strtoupper($response) == 'Y'){
        //     return true;
        // }
        // return false;

        return true;
    }

    private function runTasks(){
        if(!$this->noCreate){
            $this->outputInterface->writeln('<info>Creating project...</info>');
            $this->createProject();
        }

        $this->outputInterface->writeln('<info>Adding Domain to hosts file ('.$this->domain.')...</info>');
        $this->updateHostsFile();

        $this->outputInterface->writeln('<info>Mapping '.$this->domain.' in "'.$this->config->getHomesteadPath().'"...</info>');
        $this->updateHomesteadSites();

        $this->outputInterface->writeln('<info>Adding database ('.$this->database.') to "'.$this->config->getHomesteadPath().'"...</info>');
        $this->updateHomesteadDatabases();

        $this->outputInterface->writeln('<info>Provisioning Vagrant...</info>');
        $this->provisionHomestead();

        $this->outputInterface->writeln('<success>Complete! Visit: http://'.$this->domain.'</success>');
    }

    private function defaultDatabaseNameFromKey($key){
        $key = strtolower($key);
        $key = str_replace(' ','-',$key);
        $key = str_replace('_','-',$key);
        $key = preg_replace("/[^A-Za-z0-9\-]/", '', $key);
        return $key;
    }

    private function defaultDomainNameFromKey($key){
        $key = strtolower($key);
        $key = preg_replace("/[^A-Za-z0-9]/", '', $key);
        $key = $key.$this->config->getDomainExtension();
        return $key;
    }

    private function createProject()
    {
        $key = strtolower($this->name);
        $key = preg_replace("/[^A-Za-z0-9]/", '', $key);
        $shellOutput = shell_exec("cd {$this->folder} && sudo -H -u " . $this->config->user . " git clone {$this->composerProject} {$this->name} && cp {$this->folder}{$this->name}/.env.sample {$this->folder}{$this->name}/.env");
        file_put_contents($this->folder . $this->name . '/.env', implode('', 
          array_map(function($data) use ($key){
            return stristr($data,'DB_DATABASE=rflyte') ? "DB_DATABASE=$key'\n" : $data;
          }, file($this->folder . $this->name . '/.env'))
        ));
    }

    private function updateHostsFile(){
        shell_exec('sudo -- sh -c "echo ' . $this->config->getHostIP() . ' ' . $this->domain . ' >> /etc/hosts"');
        // $fileManager = new HostsFileManager($this->config->getHostsPath());
        // $fileManager->appendLine($this->config->getHostIP().' '.$this->domain);
    }

    private function updateHomesteadSites(){
        $fileManager = new HomesteadFileManager($this->config->getHomesteadPath());
        $fileManager->addMapLineToSites($this->domain, $this->config->getHomesteadSitesPath().$this->name.$this->folderSuffix);
    }

    private function updateHomesteadDatabases(){
        shell_exec('vagrant ssh -- -t "mysql -u homestead -e \'CREATE DATABASE ' . $this->database . ';\'"');
        //$fileManager = new HomesteadFileManager($this->config->getHomesteadPath());
        //$fileManager->addDatabase($this->database);
    }

    private function provisionHomestead(){
        $this->vagrant->provision();
    }


}