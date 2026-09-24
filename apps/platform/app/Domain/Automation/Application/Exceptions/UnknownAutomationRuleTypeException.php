<?php

namespace App\Domain\Automation\Application\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The requested rule type is not in AutomationRuleCatalog. */
class UnknownAutomationRuleTypeException extends NotFoundHttpException {}
