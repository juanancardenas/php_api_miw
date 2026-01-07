<?php

namespace App\Tests\Controller;

use App\Controller\ApiResultsCommandController;
use App\Entity\Result;
use App\Entity\User;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group('controllers')]
#[CoversClass(ApiResultsCommandController::class)]
class ApiResultsControllerTest extends BaseTestCase
{
    private const string RUTA_API = '/api/v1/results';

    /** @var array<string,string> $adminHeaders */
    protected static array $adminHeaders;

    /** @var array<string,string> $userHeaders */
    protected static array $userHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        // Generar token para admin
        self::$adminHeaders = $this->getTokenHeaders(
            self::$role_admin[User::EMAIL_ATTR],
            self::$role_admin[User::PASSWD_ATTR]
        );

        // Generar token para usuario
        self::$userHeaders = $this->getTokenHeaders(
            self::$role_user[User::EMAIL_ATTR],
            self::$role_user[User::PASSWD_ATTR]
        );
    }

    /* ===================== POST INICIAL ===================== */
    // Method POST que crea un result a partir del cual se irán ejecutando otras acciones de manera encadenadas
    // usando dependencias para garantizar el éxito del test.
    // En este escenario el administrador le crea un resultado al usuario, por lo que tanto administrador como
    // usuario tendrán permiso total sobre el resultado.

    /**
     * Test POST /results 201 Created
     * @return array<string,string> result data
     */
    public function testPostResultAction201Created(): array
    {
        $p_data = [
            Result::RESULT_ATTR => self::$faker->numberBetween(0, 10000),
            Result::TIME_ATTR => new DateTime()->format(DATE_ATOM),
            Result::USERID_ATTR => '2' // Que es el valor en BD del usuario
        ];

        self::$client->request(
            Request::METHOD_POST,
            self::RUTA_API,
            [], [],
            self::$adminHeaders,
            json_encode($p_data)
        );

        $response = self::$client->getResponse();

        // Status
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertTrue($response->isSuccessful());

        // Headers
        self::assertNotNull($response->headers->get('Location'));
        self::assertJson($response->getContent());

        // Body
        $data = json_decode($response->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(1, $data);

        $result = $data[0];
        self::assertArrayHasKey(Result::ID_ATTR, $result);
        self::assertArrayHasKey(Result::RESULT_ATTR, $result);
        self::assertArrayHasKey(Result::TIME_ATTR, $result);
        self::assertArrayHasKey(Result::USER_ATTR, $result);

        self::assertSame(
            $p_data[Result::RESULT_ATTR],
            $result[Result::RESULT_ATTR]
        );

        self::assertSame(
            self::$role_user[User::EMAIL_ATTR],   // User
            $result['user']['email']
        );

        return $result;
    }

    /**
     * Test PUT /results/{id} ETag control de concurrencia
     *  - Primer PUT OK
     *  - Segundo PUT con un ETag antiguo -> Error 412
     *  - Segundo PUT con un ETag nuevo -> OK
     * @param array<string,string> $result
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    public function testPutResultActionEtagConcurrency(array $result): void
    {
        // 1. Obtener ETag inicial
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            self::$userHeaders
        );

        $initialEtag = self::$client->getResponse()->getEtag();
        self::assertNotEmpty($initialEtag);

        // 2. Primer PUT (válido)
        $payload1 = [
            Result::RESULT_ATTR => $result[Result::RESULT_ATTR] + 10,
            Result::TIME_ATTR   => new DateTime()->format(DATE_ATOM),
            Result::USER_ATTR   => $result[Result::USER_ATTR][Result::ID_ATTR],
        ];

        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            array_merge(
                self::$userHeaders,
                ['HTTP_If-Match' => $initialEtag]
            ),
            json_encode($payload1)
        );

        $response = self::$client->getResponse();
        self::assertSame(209, $response->getStatusCode());

        $newEtag = $response->getEtag();
        self::assertNotEmpty($newEtag);
        self::assertNotSame($initialEtag, $newEtag);

        // 3. Segundo PUT usando ETag ANTIGUO → 412
        $payload2 = [
            Result::RESULT_ATTR => $payload1[Result::RESULT_ATTR] + 5,
            Result::TIME_ATTR   => new DateTime()->format(DATE_ATOM),
            Result::USER_ATTR   => $result[Result::USER_ATTR][Result::ID_ATTR],
        ];

        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            array_merge(
                self::$userHeaders,
                ['HTTP_If-Match' => $initialEtag]
            ),
            json_encode($payload2)
        );

        $response = self::$client->getResponse();
        self::assertSame(
            Response::HTTP_PRECONDITION_FAILED,
            $response->getStatusCode()
        );

        // 4. Segundo PUT usando ETag NUEVO → OK
        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            array_merge(
                self::$userHeaders,
                ['HTTP_If-Match' => $newEtag]
            ),
            json_encode($payload2)
        );

        $response = self::$client->getResponse();
        self::assertSame(209, $response->getStatusCode());
    }

    /**
     * Test HEAD /results/{id}
     * @param array<string,string> $result
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    public function testHeadResultAction200Ok(array $result): void
    {
        // Visible para usuario por HEAD
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            self::$userHeaders
        );
        $etag = self::$client->getResponse()->headers->get('ETag');
        self::assertNotEmpty($etag);

        // Visible para admin por HEAD
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            self::$adminHeaders
        );
        $etag = self::$client->getResponse()->headers->get('ETag');
        self::assertNotEmpty($etag);
    }

    /**
     * Test GET /results/{id}
     * @param array<string,string> $result
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    public function testGetResultAction200Ok(array $result): void
    {
        // Visible para usuario por GET
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            self::$userHeaders
        );
        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        // Visible para admin por GET
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            self::$adminHeaders
        );
        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * Test PUT /results/{resultId} 209 Content Returned
     * Al ser propiedad del User, el User podrá editarlo
     * @param array $result result returned by testPostResultAction201()
     * @return array<string,string> modified result data
     */
    #[Depends('testPostResultAction201Created')]
    public function testPutResultAction209ContentReturned(array $result): array
    {
        $new_result = self::$faker->numberBetween(1, 10000);
        // Aunque lo ha creado el administrador, el usuario podrá actualizar este resultado.
        $p_data = [
            Result::RESULT_ATTR => $new_result,
            Result::TIME_ATTR   => new DateTime()->format(DATE_ATOM)
        ];

        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            self::$userHeaders  // User
        );

        $etag = self::$client->getResponse()->getEtag();
        self::assertNotEmpty($etag);

        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            array_merge(self::$userHeaders, ['HTTP_If-Match' => $etag]),  // User
            json_encode($p_data)
        );

        $response = self::$client->getResponse();
        self::assertSame(209, $response->getStatusCode());

        $body = json_decode((string)$response->getContent(), true);
        $result_aux = $body[0];

        self::assertSame($result[Result::ID_ATTR], $result_aux[Result::ID_ATTR]);
        self::assertSame($p_data[Result::RESULT_ATTR], $result_aux[Result::RESULT_ATTR]);

        // GET para validar persistencia
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [], self::$userHeaders
        );

        $getResponse = self::$client->getResponse();
        self::assertSame(Response::HTTP_OK, $getResponse->getStatusCode());

        $getBody = json_decode((string)$getResponse->getContent(), true);
        $fetchedResult = $getBody['result'] ?? null;
        self::assertNotNull($fetchedResult);

        // Comparar que el valor 'result' coincide con $new_result
        self::assertSame($new_result, $fetchedResult[Result::RESULT_ATTR]);

        return $result_aux;
    }

    /**
     * Test DELETE /results/{resultId} 204 No Content
     * @param array<string,string> $result result returned by testPutResultAction209ContentReturned()
     * @return int resultId
     */
    #[Depends('testPutResultAction209ContentReturned')]
    #[Depends('testHeadResultAction200Ok')]
    #[Depends('testGetResultAction200Ok')]
    #[Depends('testPutResultActionEtagConcurrency')]
    public function testDeleteResultAction204NoContent(array $result): int
    {
        // El usuario puede borrar su resultado
        self::$client->request(
            Request::METHOD_DELETE,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [], self::$userHeaders
        );
        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertEmpty($response->getContent());

        // Si hacemos una consulta, no debe aparecer
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [], self::$userHeaders
        );
        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        return intval($result[Result::RESULT_ATTR]);
    }

    /**
     * Test DELETE /results/{resultId} 404 Not Found
     * @param int $resultId
     */
    #[Depends('testDeleteResultAction204NoContent')]
    public function testDeleteResultAction404NotFound(int $resultId): void
    {
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $resultId,
            [], [], self::$userHeaders
        );
        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $resultId,
            [], [], self::$adminHeaders
        );
        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
