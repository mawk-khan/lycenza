<?php

namespace App\Support\Http;

/**
 * SIS 2.2: a 401/403 domain refusal whose own message is safe and useful to
 * show on the browser's 403 page. WebDomainRefusalResponder keeps the 403
 * status (an authorization outcome stays one) and shows the message
 * instead of the bare "Forbidden". Opt-in per exception: implement it only
 * when the message names no record, person or relationship (it is the same
 * text the JSON API already returns).
 */
interface ExplainedRefusal {}
