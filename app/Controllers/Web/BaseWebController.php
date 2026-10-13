<?php

namespace App\Controllers\Web;

use CodeIgniter\Controller;

/**
 * Base for the operator web UI's controllers: loads the view helpers (ui_helper) every page and
 * the layout use. Signing in is enforced by UiAuthFilter on the whole `ui` route group, not here.
 */
abstract class BaseWebController extends Controller
{
    /** @var list<string> */
    protected $helpers = ['ui'];
}
