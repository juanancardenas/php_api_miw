<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\{Request, Response};

/**
 * Class ApiResultsCommandInterface
 *
 * @package App\Controller
 *
 */
interface ApiResultsCommandInterface
{
    public final const string MSG_UNAUTHORIZED = 'UNAUTHORIZED: Invalid credentials';
    public final const string MSG_NOT_FOUND = 'NOT FOUND: Result not found';
    public final const string MSG_FAILED_ETAG = 'PRECONDITION FAILED: failed to validate ETag header';
    public final const string MSG_MISSING_FIELDS = 'BAD REQUEST: Missing fields in the request';
    public final const string MSG_NOT_ALLOW = 'BAD REQUEST: Fields not allow to be included in the request';
    public final const string MSG_WRONG_RESULT = 'UNPROCESSABLE: Wrong result value';
    public final const string MSG_WRONG_TIME = 'UNPROCESSABLE: Wrong time value';
    public final const string MSG_WRONG_USERID = 'UNPROCESSABLE: Wrong userid value';
    public final const string MSG_ERROR_ETAG = 'SERVER ERROR: Error generating ETag';

    /**
     * **DELETE** Action<br><br>
     * Summary: Removes the Result resource<br>
     * _Notes_: Deletes the result identified by <code>resultId</code>
     *
     * @param int $resultId Result id
     */
    public function deleteAction(Request $request, int $resultId): Response;

    /**
     * **POST** action<br><br>
     * Summary: Creates a Result resource<br>
     *
     * @param Request $request request
     */
    public function postAction(Request $request): Response;

    /**
     * **PUT** action<br><br>
     * Summary: Updates the Result resource<br>
     * _Notes_: Updates the result identified by <code>resultId</code>.
     *
     * @param Request $request request
     * @param int $resultId Result id
     */
    public function putAction(Request $request, int $resultId): Response;
}
