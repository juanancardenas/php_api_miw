<?php

namespace App\Tests\Controller;

use App\Controller\ApiResultsQueryController;
use DateMalformedStringException;
use App\Entity\{Result, User};
use DateTime;
use Generator;
use JetBrains\PhpStorm\ArrayShape;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Depends, Group};
use PHPUnit\Framework\MockObject\Exception;
use Symfony\Component\HttpFoundation\{ Request, Response};

#[Group('controllers')]
#[CoversClass(ApiResultsQueryController::class)]
class ApiResultsControllerTest extends BaseTestCase
{
    private const string RUTA_API = '/api/v1/results';

    /** @var array<string,string> $adminHeaders */
    protected static array $adminHeaders;

    /**
     * Test OPTIONS /results y /results/{resultId} 204 No Content
     * @return void
     */
    public function testOptionsResultAction204NoContent(): void
    {
        // OPTIONS /api/v1/results
        self::$client->request(
            Request::METHOD_OPTIONS,
            self::RUTA_API
        );
        $response = self::$client->getResponse();

        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $response->getStatusCode()
        );
        self::assertNotEmpty($response->headers->get('Allow'));

        // OPTIONS /api/v1/results/{id}
        self::$client->request(
            Request::METHOD_OPTIONS,
            self::RUTA_API . '/' . self::$faker->numberBetween(1, 100)
        );

        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $response->getStatusCode()
        );
        self::assertNotEmpty($response->headers->get('Allow'));
    }

    /**
     * Test POST /results 201 Created
     * @return array<string,string> result data
     */
    public function testPostResultAction201Created(): array
    {
        $p_data = [
            Result::RESULT_ATTR => self::$faker->numberBetween(0, 10000),
            Result::TIME_ATTR   => new DateTime()->format(DATE_ATOM),
        ];

        self::$adminHeaders = $this->getTokenHeaders(
            self::$role_user[User::EMAIL_ATTR],
            self::$role_user[User::PASSWD_ATTR]
        );

        self::$client->request(
            Request::METHOD_POST,
            self::RUTA_API,
            [],
            [],
            self::$adminHeaders,
            json_encode($p_data)
        );

        $response = self::$client->getResponse();
        //dump($response->getContent());
        //php ./bin/phpunit --filter testPostResultAction201Created

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
            self::$role_user[User::EMAIL_ATTR],
            $result['user']['email']
        );

        return $result;
    }

    /** TEST GET **/

    /**
     * Test GET /results 200 Ok
     * @return string ETag header
     */
    #[Depends('testPostResultAction201Created')]
    public function testCGetResultAction200Ok(): string
    {
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API,
            [],
            [],
            self::$adminHeaders
        );
        $response = self::$client->getResponse();

        self::assertTrue($response->isSuccessful());
        self::assertNotNull($response->getEtag());

        $r_body = strval($response->getContent());
        self::assertJson($r_body);
        $results = json_decode($r_body, true);
        self::assertArrayHasKey('results', $results);

        return (string) $response->getEtag();
    }

    /**
     * Test GET /results 304 NOT MODIFIED
     * @param string $etag returned by testCGetResultAction200Ok
     */
    #[Depends('testCGetResultAction200Ok')]
    public function testCGetResultAction304NotModified(string $etag): void
    {
        $headers = array_merge(
            self::$adminHeaders,
            [ 'HTTP_If-None-Match' => [$etag] ]
        );
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API,
            [],
            [],
            $headers
        );
        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
    }

    /**
     * Test GET /results/{resultId} 200 Ok (with XML header)
     * @param array<string,string> $result Result returned by testPostResultAction201()
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    public function testCGetResultAction200XmlOk(array $result)
    {
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR] . '.xml',
            [],
            [],
            array_merge(
                self::$adminHeaders,
                [ 'HTTP_ACCEPT' => 'application/xml' ]
            )
        );
        $response = self::$client->getResponse();
        self::assertTrue($response->isSuccessful(), strval($response->getContent()));
        self::assertNotNull($response->getEtag());
        self::assertTrue($response->headers->contains('content-type', 'application/xml'));
    }

    /**
     * Test GET /results/{resultId} 200 Ok
     * @param array<string,string> $result Result returned by testPostResultAction201()
     * @return string ETag header
     */
    #[Depends('testPostResultAction201Created')]
    public function testGetResultAction200Ok(array $result): string
    {
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            self::$adminHeaders
        );
        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotNull($response->getEtag());
        $r_body = (string) $response->getContent();
        self::assertJson($r_body);
        $result_aux = json_decode($r_body, true)[Result::RESULT_ATTR];
        self::assertSame($result[Result::ID_ATTR], $result_aux[Result::ID_ATTR]);

        return (string) $response->getEtag();
    }

    /**
     * Test GET /results/{resultId} 304 NOT MODIFIED
     * @param array<string,string> $result Result returned by testPostResultAction201Created()
     * @param string $etag returned by testGetResultAction200Ok
     * @return string Entity Tag
     */
    #[Depends('testPostResultAction201Created')]
    #[Depends('testGetResultAction200Ok')]
    public function testGetResultAction304NotModified(array $result, string $etag): string
    {
        $headers = array_merge(
            self::$adminHeaders,
            [ 'HTTP_If-None-Match' => [$etag] ]
        );
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            $headers);
        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());

        return $etag;
    }

    /** TEST HEAD **/

    /**
     * Test HEAD /results 200 OK
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    #[Depends('testGetResultAction200Ok')]
    public function testHeadResultsAction200Ok(): void
    {
        // Autenticación válida
        self::$adminHeaders = $this->getTokenHeaders(
            self::$role_user[User::EMAIL_ATTR],
            self::$role_user[User::PASSWD_ATTR]
        );

        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API,
            [], [],
            self::$adminHeaders
        );

        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('', $response->getContent()); // HEAD no devuelve body
        self::assertNotNull($response->getEtag());
    }

    /**
     * Test HEAD /results 401 Unauthorized
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    #[Depends('testGetResultAction200Ok')]
    public function testHeadResultsAction401Unauthorized(): void
    {
        // Sin autenticación
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API
        );

        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * Test HEAD /results 404 Not Found
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    #[Depends('testGetResultAction200Ok')]
    public function testHeadResultsAction404NotFound(): void
    {
        // Simulamos 404 forzando un endpoint que no exista
        self::$adminHeaders = $this->getTokenHeaders(
            self::$role_user[User::EMAIL_ATTR],
            self::$role_user[User::PASSWD_ATTR]
        );

        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/999999', // ID que no existe
            [], [],
            self::$adminHeaders
        );

        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /**
     * Test HEAD /results/{resultId} 200 Ok
     * @param array<string,string> $result Result returned by testPostResultAction201()
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    #[Depends('testGetResultAction200Ok')]
    public function testHeadResultAction200Ok(array $result): void
    {
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            self::$adminHeaders
        );

        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /**
     * Test HEAD /results/{resultId} 304 Not Modified
     * @param array<string,string> $result Result returned by testPostResultAction201()
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    #[Depends('testGetResultAction200Ok')]
    public function testHeadResultAction304NotModified(array $result): void
    {
        // Primero obtenemos el ETag vía GET (ya probado que funciona)
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            self::$adminHeaders
        );

        $etag = self::$client->getResponse()->headers->get('ETag');
        self::assertNotEmpty($etag);

        // Ahora el HEAD con If-None-Match
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            array_merge(
                self::$adminHeaders,
                ['HTTP_IF_NONE_MATCH' => $etag]
            )
        );

        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /**
     * Test HEAD /results/{resultId} 401 Unauthorized
     * @param array<string,string> $result Result returned by testPostResultAction201()
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    #[Depends('testGetResultAction200Ok')]
    public function testHeadResultAction401Unauthorized(array $result): void
    {
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR]
        // sin headers de autenticación
        );

        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * Test HEAD /results/{resultId} 404 Not found
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    #[Depends('testGetResultAction200Ok')]
    public function testHeadResultAction404NotFound(): void
    {
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/999999',
            [],
            [],
            self::$adminHeaders
        );

        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /** TEST POST **/

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
            [],
            [],
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
            [],
            [],
            self::$adminHeaders,
            strval(json_encode($p_data))
        );
        $this->checkResponseErrorMessage(
            self::$client->getResponse(),
            Response::HTTP_UNPROCESSABLE_ENTITY
        );

        return $result;
    }

    /** TEST PUT **/

    /**
     * Test PUT /results/{resultId} 209 Content Returned
     * @param array $result result returned by testPostResultAction201()
     * @param string $etag returned by testGetResultAction304NotModified()
     * @return array<string,string> modified result data
     * @throws Exception|DateMalformedStringException
     */
    #[Depends('testPostResultAction201Created')]
    #[Depends('testGetResultAction304NotModified')]
    #[Depends('testCGetResultAction304NotModified')]
    #[Depends('testPostResultAction400BadRequest')]
    public function testPutResultAction209ContentReturned(array $result, string $etag): array
    {
        // Stub de usuario
        $userStub = $this->createStub(User::class);
        $userStub->method('getId')->willReturn($result['user']['id']);
        $userStub->method('getEmail')->willReturn($result['user']['email']);

        // Crear objeto Result temporal
        $resultObj = new Result(
            $result[Result::RESULT_ATTR],
            $userStub,
            new DateTime($result[Result::TIME_ATTR])
        );

        // Payload correcto
        $p_data = [
            Result::RESULT_ATTR => self::$faker->numberBetween(1, 10000),
            Result::TIME_ATTR   => new DateTime()->format('Y-m-d H:i:s'),
            Result::USER_ATTR   => $resultObj->getUser()->getId(),
        ];

        // Ejecutar PUT
        self::$client->request(
            Request::METHOD_PUT,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [], [],
            array_merge(self::$adminHeaders, ['HTTP_If-Match' => $etag]),
            json_encode($p_data)
        );

        $response = self::$client->getResponse();
        $r_body = (string) $response->getContent();

        self::assertSame(209, $response->getStatusCode());
        self::assertJson($r_body);

        $result_aux_array = json_decode($r_body, true);
        $result_aux = $result_aux_array[0];

        // Validaciones
        self::assertSame($result[Result::ID_ATTR], $result_aux[Result::ID_ATTR]);
        self::assertSame($p_data[Result::RESULT_ATTR], $result_aux[Result::RESULT_ATTR]);
        self::assertSame($p_data[Result::USER_ATTR], $result_aux[Result::USER_ATTR][Result::ID_ATTR]);

        return $result_aux;
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
            [],
            [],
            self::$adminHeaders
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
            [],
            [],
            array_merge(
                self::$adminHeaders,
                [ 'HTTP_If-Match' => $etag ]
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
            [],
            [],
            self::$adminHeaders
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
            [],
            [],
            self::$adminHeaders
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
            [],
            [],
            array_merge(
                self::$adminHeaders,
                [ 'HTTP_If-Match' => $etag ]
            ),
            strval(json_encode($p_data))
        );
        $response = self::$client->getResponse();
        $this->checkResponseErrorMessage($response, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** TEST DElETE **/

    /**
     * Test DELETE /results/{resultId} 204 No Content
     * @param array<string,string> $result result returned by testPostResultAction400BadRequest()
     * @return int resultId
     */
    #[Depends('testPostResultAction400BadRequest')]
    #[Depends('testPutResultAction412PreconditionFailed')]
    #[Depends('testPutResultAction403Forbidden')]
    #[Depends('testCGetResultAction200XmlOk')]
    #[Depends('testPutResultAction400BadRequest')]
    public function testDeleteResultAction204NoContent(array $result): int
    {
        self::$client->request(
            Request::METHOD_DELETE,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            self::$adminHeaders
        );
        $response = self::$client->getResponse();

        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $response->getStatusCode()
        );
        self::assertEmpty($response->getContent());

        return intval($result[Result::RESULT_ATTR]);
    }

    /**
     * Test GET /results/{resultId} 404 NOT FOUND
     * Test PUT /results/{resultId} 404 NOT FOUND
     * Test DELETE /results/{resultId} 404 NOT FOUND
     * @param string $method
     * @param int $userId user id. returned by testDeleteUserAction204()
     * @return void
     */
    #[Depends('testDeleteResultAction204NoContent')]
    #[DataProvider('providerRoutes404')]
    public function testResultStatus404NotFound(string $method, int $userId): void
    {
        self::$client->request(
            $method,
            self::RUTA_API . '/' . $userId,
            [],
            [],
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
            [],
            [],
            [ 'HTTP_ACCEPT' => 'application/json' ]
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
        yield 'cgetAction401'   => [ Request::METHOD_GET,    self::RUTA_API ];
        yield 'getAction401'    => [ Request::METHOD_GET,    self::RUTA_API . '/1' ];
        yield 'postAction401'   => [ Request::METHOD_POST,   self::RUTA_API ];
        yield 'putAction401'    => [ Request::METHOD_PUT,    self::RUTA_API . '/1' ];
        yield 'deleteAction401' => [ Request::METHOD_DELETE, self::RUTA_API . '/1' ];
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
        yield 'getAction404'    => [ Request::METHOD_GET ];
        yield 'putAction404'    => [ Request::METHOD_PUT ];
        yield 'deleteAction404' => [ Request::METHOD_DELETE ];
    }
}
