<?php

namespace App\Tests\Controller;

use App\Controller\ApiResultsCommandController;
use App\Entity\{Result, User};
use DateTime;
use Generator;
use JetBrains\PhpStorm\ArrayShape;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Depends, Group};
use Symfony\Component\HttpFoundation\{Request, Response};

#[Group('controllers')]
#[CoversClass(ApiResultsCommandController::class)]
class ApiResultsCommandControllerTest extends BaseTestCase
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
    // En este escenario el administrador se crea un resultado a sí mismo. El usuario no tendrá ningún permiso
    // sobre el nuevo resultado creado por el administrador.

    /**
     * Test POST /results 201 Created
     * @return array<string,string> result data
     */
    public function testPostResultAction201Created(): array
    {
        $p_data = [
            Result::RESULT_ATTR => self::$faker->numberBetween(1, 10000),
            Result::TIME_ATTR => new DateTime()->format(DATE_ATOM),
        ];

        self::$client->request(
            Request::METHOD_POST,
            self::RUTA_API,
            [],
            [],
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
            self::$role_admin[User::EMAIL_ATTR],    // Admin
            $result['user']['email']
        );

        return $result;
    }

    /* ===================== POST ===================== */

    /**
     * Test POST /results 400 Bad Request
     * @param array<string,string> $result Result returned by testPostResultAction201Created()
     * @return array<string,string> result data
     */
    #[Depends('testPostResultAction201Created')]
    public function testPostResultAction400BadRequest(array $result): array
    {
        $p_data = [
            Result::ID_ATTR => $result[Result::ID_ATTR], // Mismo id -> error
            Result::RESULT_ATTR => self::$faker->numberBetween(1, 10000),
            Result::TIME_ATTR => self::$faker->time(),
        ];
        self::$client->request(
            Request::METHOD_POST,
            self::RUTA_API,
            [], [],
            self::$adminHeaders,
            strval(json_encode($p_data))
        );
        $this->checkResponseErrorMessage(
            self::$client->getResponse(),
            Response::HTTP_BAD_REQUEST
        );

        return $result;
    }

    /**
     * Test POST /results 422 Unprocessable Entity
     * @param array<string,string> $result Result returned by testPostResultAction201Created()
     * @return array<string,string> result data
     */
    #[Depends('testPostResultAction201Created')]
    public function testPostResultsAction422UnprocessableEntity(array $result): array
    {
        $p_data = [
            Result::RESULT_ATTR => self::$faker->numberBetween(1, 10000),
            Result::TIME_ATTR => 'not-a-date',  // Valor incorrecto
        ];
        self::$client->request(
            Request::METHOD_POST,
            self::RUTA_API,
            [], [],
            self::$adminHeaders,
            strval(json_encode($p_data))
        );
        $this->checkResponseErrorMessage(
            self::$client->getResponse(),
            Response::HTTP_UNPROCESSABLE_ENTITY
        );

        return $result;
    }

    /* ===================== PUT ===================== */

    /**
     * Test PUT /results/{resultId} 209 Content Returned
     * Al ser propiedad del Admin, el User no podrá editarlo
     * @param array $result result returned by testPostResultAction201()
     * @return array<string,string> modified result data
     */
    #[Depends('testPostResultAction201Created')]
    public function testPutResultAction209ContentReturned(array $result): array
    {
        // Sólo el administrador podrá actualizar este resultado.
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            self::$adminHeaders
        );

        $etag = self::$client->getResponse()->getEtag();
        self::assertNotEmpty($etag);

        $p_data = [
            Result::RESULT_ATTR => self::$faker->numberBetween(1, 10000),
            Result::TIME_ATTR   => new DateTime()->format(DATE_ATOM),
            Result::USERID_ATTR => $result[Result::USER_ATTR][Result::ID_ATTR],
        ];

        // El admin hace el PUT
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            self::$adminHeaders
        );

        $etag = self::$client->getResponse()->getEtag();
        self::assertNotEmpty($etag);

        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            array_merge(self::$adminHeaders, ['HTTP_If-Match' => $etag]),
            json_encode($p_data)
        );

        $response = self::$client->getResponse();
        self::assertSame(209, $response->getStatusCode());

        $body = json_decode((string)$response->getContent(), true);
        $result_aux = $body[0];

        self::assertSame($result[Result::ID_ATTR], $result_aux[Result::ID_ATTR]);
        self::assertSame($p_data[Result::RESULT_ATTR], $result_aux[Result::RESULT_ATTR]);

        return $result_aux;
    }

    // Misma prueba pero por el User -> no puede hacer PUT sobre resultados ajenos
    #[Depends('testPostResultAction201Created')]
    public function testPutResultActionAsUserNotFound(array $result): void
    {
        // HEAD como user → 404
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [], self::$userHeaders
        );
        self::assertSame(Response::HTTP_NOT_FOUND, self::$client->getResponse()->getStatusCode());

        // PUT como user → 404
        $p_data = [
            Result::RESULT_ATTR => self::$faker->numberBetween(1, 10000),
            Result::TIME_ATTR => new DateTime()->format(DATE_ATOM),
            Result::USERID_ATTR => $result[Result::USER_ATTR][Result::ID_ATTR],
        ];
        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            self::$userHeaders,
            json_encode($p_data)
        );
        self::assertSame(Response::HTTP_NOT_FOUND, self::$client->getResponse()->getStatusCode());
    }

    /**
     * Test PUT /results/{resultId} 400 Bad Request
     * @param array<string,string> $result result returned by testPutResultAction209()
     * @return void
     */
    #[Depends('testPutResultAction209ContentReturned')]
    public function testPutResultAction400BadRequest(array $result): void
    {
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],[],self::$adminHeaders
        );
        $etag = self::$client->getResponse()->getEtag();

        // El campo id no debe incluirse en el payload
        $p_data = [
            Result::ID_ATTR => $result[Result::ID_ATTR],
            Result::RESULT_ATTR => $result[Result::RESULT_ATTR],
            Result::TIME_ATTR => $result[Result::TIME_ATTR],
        ];

        // Hacer el PUT
        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            array_merge(
                self::$adminHeaders,
                ['HTTP_If-Match' => $etag]
            ),
            strval(json_encode($p_data))
        );
        $response = self::$client->getResponse();
        $this->checkResponseErrorMessage($response, Response::HTTP_BAD_REQUEST);
    }

    /**
     * Test PUT /results/{resultId} 412 PRECONDITION_FAILED
     * @param array<string,string> $result result returned by testPutResultAction209ContentReturned()
     * @return void
     */
    #[Depends('testPutResultAction209ContentReturned')]
    public function testPutResultAction412PreconditionFailed(array $result): void
    {
        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [], self::$adminHeaders
        );
        $response = self::$client->getResponse();
        $this->checkResponseErrorMessage($response, Response::HTTP_PRECONDITION_FAILED);
    }

    /**
     * Test PUT /results/{resultId} 422 Unprocessable Entity
     * @param array<string,string> $result result returned by testPutResultAction209()
     * @return void
     */
    #[Depends('testPutResultAction209ContentReturned')]
    public function testPutResultAction422Unprocessable(array $result): void
    {
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [], self::$adminHeaders
        );
        $etag = self::$client->getResponse()->getEtag();

        // Result con valor negativo
        $p_data = [
            Result::RESULT_ATTR => self::$faker->numberBetween(-1000, -1),
            Result::TIME_ATTR => $result[Result::TIME_ATTR],
            Result::USER_ATTR => $result[Result::USER_ATTR],
        ];
        // Hacer el PUT
        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            array_merge(
                self::$adminHeaders,
                ['HTTP_If-Match' => $etag]
            ),
            strval(json_encode($p_data))
        );
        $response = self::$client->getResponse();
        $this->checkResponseErrorMessage($response, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /* ===================== DELETE ===================== */

    /**
     * Test DELETE /results/{resultId} 204 No Content
     * @param array<string,string> $result result returned by testPutResultAction209ContentReturned()
     * @return int resultId
     */
    #[Depends('testPutResultAction209ContentReturned')]
    #[Depends('testPutResultAction400BadRequest')]
    #[Depends('testPutResultAction412PreconditionFailed')]
    #[Depends('testPutResultAction422Unprocessable')]
    public function testDeleteResultAction204NoContent(array $result): int
    {
        // El usuario no debería tener visibilidad
        self::$client->request(
            Request::METHOD_DELETE,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [], self::$userHeaders
        );
        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        // El admnistrador puede porque es suyo
        self::$client->request(
            Request::METHOD_DELETE,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [], self::$adminHeaders
        );
        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertEmpty($response->getContent());

        // Si hacemos una consulta, no debe aparecer
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [], self::$adminHeaders
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
            [], [], self::$adminHeaders
        );
        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * Test GET /results/{resultId} 404 NOT FOUND
     * Test PUT /results/{resultId} 404 NOT FOUND
     * Test DELETE /results/{resultId} 404 NOT FOUND
     * @param string $method
     * @param int $resultId
     * @return void
     */
    #[Depends('testDeleteResultAction204NoContent')]
    #[DataProvider('providerRoutes404')]
    public function testResultStatus404NotFound(string $method, int $resultId): void
    {
        self::$client->request(
            $method,
            self::RUTA_API . '/' . $resultId,
            [], [],
            self::$adminHeaders
        );
        $this->checkResponseErrorMessage(
            self::$client->getResponse(),
            Response::HTTP_NOT_FOUND
        );
    }

    /**
     * Test GET /results 401 UNAUTHORIZED
     * Test POST /results 401 UNAUTHORIZED
     * Test GET /results/{resultId} 401 UNAUTHORIZED
     * Test PUT /results/{resultId} 401 UNAUTHORIZED
     * Test DELETE results/{resultId} 401 UNAUTHORIZED
     *
     * @param string $method
     * @param string $uri
     * @return void
     */
    #[DataProvider('providerRoutes401')]
    public function testResultStatus401Unauthorized(string $method, string $uri): void
    {
        self::$client->request(
            $method,
            $uri,
            [], [],
            ['HTTP_ACCEPT' => 'application/json']
        );
        $this->checkResponseErrorMessage(
            self::$client->getResponse(),
            Response::HTTP_UNAUTHORIZED
        );
    }

    /**
     * * * * * * * * * *
     * P R O V I D E R S
     * * * * * * * * * *
     */

    /**
     * Route provider (expected status: 401 UNAUTHORIZED)
     * @return Generator name => [ method, url ]
     */
    #[ArrayShape([
        'cgetAction401' => "array",
        'getAction401' => "array",
        'postAction401' => "array",
        'putAction401' => "array",
        'deleteAction401' => "array"
    ])]
    public static function providerRoutes401(): Generator
    {
        yield 'cgetAction401' => [Request::METHOD_GET, self::RUTA_API];
        yield 'getAction401' => [Request::METHOD_GET, self::RUTA_API . '/1'];
        yield 'postAction401' => [Request::METHOD_POST, self::RUTA_API];
        yield 'putAction401' => [Request::METHOD_PUT, self::RUTA_API . '/1'];
        yield 'deleteAction401' => [Request::METHOD_DELETE, self::RUTA_API . '/1'];
    }

    /**
     * Route provider (expected status 404 NOT FOUND)
     * @return Generator name => [ method ]
     */
    #[ArrayShape([
        'getAction404' => "array",
        'putAction404' => "array",
        'deleteAction404' => "array"
    ])]
    public static function providerRoutes404(): Generator
    {
        yield 'getAction404' => [Request::METHOD_GET];
        yield 'putAction404' => [Request::METHOD_PUT];
        yield 'deleteAction404' => [Request::METHOD_DELETE];
    }
}
