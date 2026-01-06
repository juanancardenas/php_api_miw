<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * Class ApiResultsQueryInterface
 *
 * @package App\Controller
 *
 */
interface ApiResultsQueryInterface
{
    public final const string RUTA_API = '/api/v1/results';

    public final const string ROLE_ADMIN = 'ROLE_ADMIN';
    public final const string HEADER_CACHE_CONTROL = 'Cache-Control';
    public final const string HEADER_ETAG = 'ETag';
    public final const string HEADER_ALLOW = 'Allow';
    public final const string MSG_UNAUTHORIZED = 'UNAUTHORIZED: Invalid credentials';
    public final const string MSG_NOT_FOUND = 'NOT FOUND: Result not found';
    public final const string MSG_NOT_MODIFIED = 'NOT MODIFIED: Resource has not been modified';
    public final const string MSG_ERROR_ETAG = 'SERVER ERROR: Error generating ETag';

    /**
     * **CGET** Action<br><br>
     * Summary: Retrieves the collection of Result resources.<br>
     * _Notes_: Returns all results from the system that the user has access to.
     */
    public function cgetAction(Request $request): Response;

    /**
     * **GET** Action<br><br>
     * Summary: Retrieves a Result resource based on a single ID.<br>
     * _Notes_: Returns the result identified by <code>resultId</code>.
     *
     * @param int $resultId Result id
     */
    public function getAction(Request $request, int $resultId): Response;

    /**
     * **OPTIONS** Action<br><br>
     * Summary: Provides the list of HTTP supported methods<br>
     * _Notes_: Return a <code>Allow</code> header with a list of HTTP supported methods.
     *
     * @param  int|null $resultId Result id
     */
    public function optionsAction(?int $resultId): Response;
}
