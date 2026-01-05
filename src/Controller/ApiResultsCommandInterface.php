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
