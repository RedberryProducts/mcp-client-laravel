<?php

namespace Redberry\MCPClient\Contracts;


interface MCPClient
{

    public function connect(string $serverName): self;

    public function tools();

    public function resources();

}
