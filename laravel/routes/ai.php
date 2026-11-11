<?php

use App\Mcp\Servers\EtabliServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('etabli', EtabliServer::class);
