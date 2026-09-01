<?php
namespace App\Exceptions;
use RuntimeException;
class CpanelException extends RuntimeException { public function __construct(string $message,private readonly string $safeMessage,private readonly array $diagnostic=[]){parent::__construct($message);} public function safeMessage():string{return $this->safeMessage;} public function diagnostic():array{return $this->diagnostic;} }
