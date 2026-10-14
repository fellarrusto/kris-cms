<?php
declare(strict_types=1);

namespace Kris\Agent;

use RuntimeException;

/** Errore dell'agente o dell'abbinamento. Il messaggio si puo mostrare all'admin; il codice e lo stato HTTP. */
final class AgentException extends RuntimeException
{
}
