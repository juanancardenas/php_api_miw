<?php

namespace App\Tests\Controller;

use App\Controller\ApiResultsQueryController;
use App\Entity\{Result, User};
use DateTime;
use PHPUnit\Framework\Attributes\{CoversClass, Depends, Group};
use Symfony\Component\HttpFoundation\{Request, Response};

#[Group('controllers')]
#[CoversClass(ApiResultsQueryController::class)]
class ApiResultsQueryControllerTest extends BaseTestCase
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

    /* ===================== OPTIONS ===================== */

    /**
     * Test OPTIONS /results y /results/{resultId} 204 No Content
     * En este escenario el usuario se crea así mismo un resultado
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

    /* ===================== POST INICIAL ===================== */
    // Method POST que crea un result a partir del cual se irán ejecutando otras acciones de manera encadenadas
    // usando dependencias para garantizar el éxito del test.
    // En este escenario el usuario se crea un resultado, por lo que tendrá permiso total sobre el mismo.

    /**
     * Test POST /results 201 Created
     * @return array<string,string> result data
     */
    public function testPostResultAction201Created(): array
    {
        $p_data = [
            Result::RESULT_ATTR => self::$faker->numberBetween(0, 10000),
            Result::TIME_ATTR => new DateTime()->format(DATE_ATOM),
        ];

        self::$client->request(
            Request::METHOD_POST,
            self::RUTA_API,
            [],
            [],
            self::$userHeaders,
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
            self::$role_user[User::EMAIL_ATTR],   // User
            $result['user']['email']
        );

        return $result;
    }

    /* ===================== GET ===================== */

    /**
     * Test GET /results 200 Ok
     * @return string ETag header
     */
    #[Depends('testPostResultAction201Created')]
    public function testCGetResultAction200Ok(): string
    {
        // Debe ser visible para el usuario
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API,
            [],
            [],
            self::$userHeaders
        );
        $response = self::$client->getResponse();

        self::assertTrue($response->isSuccessful());
        self::assertNotNull($response->getEtag());

        $r_body = strval($response->getContent());
        self::assertJson($r_body);
        $results = json_decode($r_body, true);
        self::assertArrayHasKey('results', $results);

        // Debe ser visible para el admin
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

        return (string)$response->getEtag();
    }

    /**
     * Test GET /results 304 NOT MODIFIED
     * @param string $etag returned by testCGetResultAction200Ok
     */
    #[Depends('testCGetResultAction200Ok')]
    public function testCGetResultAction304NotModified(string $etag): void
    {
        // Debe ser visible para el usuario
        $headers = array_merge(
            self::$userHeaders,
            ['HTTP_If-None-Match' => [$etag]]
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

        // Debe ser visible para el admin
        $headers = array_merge(
            self::$adminHeaders,
            ['HTTP_If-None-Match' => [$etag]]
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
        // Debe ser visible para el usuario
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR] . '.xml',
            [],
            [],
            array_merge(
                self::$userHeaders,
                ['HTTP_ACCEPT' => 'application/xml']
            )
        );
        $response = self::$client->getResponse();
        self::assertTrue($response->isSuccessful(), strval($response->getContent()));
        self::assertNotNull($response->getEtag());
        self::assertTrue($response->headers->contains('content-type', 'application/xml'));

        // Debe ser visible para el admin
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR] . '.xml',
            [],
            [],
            array_merge(
                self::$adminHeaders,
                ['HTTP_ACCEPT' => 'application/xml']
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
        // Debe ser visible para el admin
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            self::$adminHeaders
        );
        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        // Debe ser visible para el usuario
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            self::$userHeaders
        );
        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNotNull($response->getEtag());
        $r_body = (string)$response->getContent();
        self::assertJson($r_body);
        $result_aux = json_decode($r_body, true)[Result::RESULT_ATTR];
        self::assertSame($result[Result::ID_ATTR], $result_aux[Result::ID_ATTR]);

        return (string)$response->getEtag();
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
        // Debe ser visible para el admin
        $headers = array_merge(
            self::$userHeaders,
            ['HTTP_If-None-Match' => [$etag]]
        );
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            $headers);
        $response = self::$client->getResponse();
        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());

        // Debe ser visible para el user
        $headers = array_merge(
            self::$userHeaders,
            ['HTTP_If-None-Match' => [$etag]]
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

    /* ===================== HEAD ===================== */

    /**
     * Test HEAD /results 200 OK
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    public function testHeadResultsAction200Ok(): void
    {
        // Debe ser visible para el user
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API,
            [], [],
            self::$userHeaders
        );

        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('', $response->getContent()); // HEAD no devuelve body
        self::assertNotNull($response->getEtag());

        // Debe ser visible para el admin
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
     * Test HEAD /results/{resultId} 200 Ok
     * @param array<string,string> $result Result returned by testPostResultAction201()
     * @return void
     */
    #[Depends('testPostResultAction201Created')]
    public function testHeadResultAction200Ok(array $result): void
    {
        // Debe ser visible para el user
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            self::$userHeaders
        );

        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('', $response->getContent());

        // Debe ser visible para el admin
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
    public function testHeadResultAction304NotModified(array $result): void
    {
        // (1) Para el user
        // Primero obtenemos el ETag vía GET (ya probado que funciona)
        self::$client->request(
            Request::METHOD_GET,
            self::RUTA_API . '/' . $result[Result::ID_ATTR],
            [],
            [],
            self::$userHeaders
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
                self::$userHeaders,
                ['HTTP_IF_NONE_MATCH' => $etag]
            )
        );

        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        self::assertSame('', $response->getContent());


        // (2) Para el admin
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
    public function testHeadResultAction404NotFound(): void
    {
        self::$client->request(
            Request::METHOD_HEAD,
            self::RUTA_API . '/999999',
            [],
            [],
            self::$userHeaders
        );

        $response = self::$client->getResponse();

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('', $response->getContent());


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
}
