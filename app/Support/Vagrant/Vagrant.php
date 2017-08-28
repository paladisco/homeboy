<?php

namespace App\Support\Vagrant;

class Vagrant{

    private $accessDirectoryCommand;

    public function __construct($accessDirectoryCommand)
    {
        $this->setAccessDirectoryCommand($accessDirectoryCommand);
    }

    public function setAccessDirectoryCommand($accessDirectoryCommand){
        $this->accessDirectoryCommand = $accessDirectoryCommand;
    }

    public function provision(){
        return $this->runAction();
    }

    public function runAction(){
        return shell_exec('');
    }

}