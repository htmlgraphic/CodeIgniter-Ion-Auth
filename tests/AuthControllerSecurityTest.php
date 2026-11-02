<?php

namespace IonAuth\Tests;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use IonAuth\Controllers\Auth;
use IonAuth\Models\IonAuthModel;

class AuthControllerSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait {
        setUpDatabase as protected traitSetUpDatabase;
    }

    protected $namespace = 'IonAuth';
    protected $seed = 'IonAuth\\Database\\Seeds\\IonAuthSeeder';
    private IonAuthModel $model;

    protected function setUpDatabase()
    {
        config('IonAuth')->databaseGroupName = 'tests';
        config('IonAuth')->bcryptDefaultCost = 4;
        config('IonAuth')->bcryptAdminCost = 4;
        config('IonAuth')->useCiEmail = false;
        $this->traitSetUpDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->model = new IonAuthModel();
    }

    private function controller(array $post, array $requestData = []): Auth
    {
        $request = new IncomingRequest(config('App'), new SiteURI(config('App'), 'auth'), null, new UserAgent());
        $request->setMethod('POST');
        $request->setGlobal('server', ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on']);
        $request->setGlobal('post', $post);
        $request->setGlobal('request', $requestData ?: $post);
        Services::injectMock('request', $request);
        Services::resetSingle('validation');
        $controller = new Auth();
        $controller->initController($request, service('response'), service('logger'));
        return $controller;
    }

    public function testRegistrationValidatesAndStoresNormalizedPost(): void
    {
        $this->assertTrue($this->model->login('admin@admin.com', 'password'));
        $post = ['first_name' => '  Alice  ', 'last_name' => '  Example  ',
            'email' => '  alice@example.test  ', 'company' => '  Example  ', 'phone' => '  123  ',
            'password' => '  preserved password  ', 'password_confirm' => '  preserved password  '];
        // REQUEST remains padded, as it does with the unmodified #1597 implementation.
        $response = $this->controller($post)->create_user();
        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $response);
        $user = $this->model->where('email', 'alice@example.test')->users()->row();
        $this->assertNotNull($user);
        $this->assertSame('Alice', $user->first_name);
        $this->assertSame('Example', $user->company);
        $this->assertTrue($this->model->verifyPassword('  preserved password  ', $user->password));
    }

    public function testNormalizedDuplicateIsRejected(): void
    {
        $this->assertTrue($this->model->login('admin@admin.com', 'password'));
        $controller = $this->controller(['first_name' => 'Alice', 'last_name' => 'Example',
            'email' => '  admin@admin.com  ', 'password' => 'test-password', 'password_confirm' => 'test-password']);
        $controller->create_user();
        $this->assertSame(1, $this->model->db()->table(config('IonAuth')->tables['users'])->countAllResults());
        $this->assertArrayHasKey('email', service('validation')->getErrors());
    }

    public function testRegistrationRemainsAdminOnly(): void
    {
        $response = $this->controller(['email' => 'alice@example.test'])->create_user();
        $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $response);
        $this->assertSame(1, $this->model->db()->table(config('IonAuth')->tables['users'])->countAllResults());
    }

    public function testLoginUsesPostRatherThanMergedRequest(): void
    {
        $this->controller(['identity' => 'admin@admin.com', 'password' => 'wrong'],
            ['identity' => 'admin@admin.com', 'password' => 'password'])->login();
        $this->assertNull(session()->get('user_id'));
    }

    public function testFailedLoginDoesNotFlashPassword(): void
    {
        $this->controller(['identity' => 'admin@admin.com', 'password' => 'private-wrong-password'])->login();
        $this->assertSame(['post' => ['identity' => 'admin@admin.com'], 'get' => []], session()->getFlashdata('_ci_old_input'));
        $this->assertStringNotContainsString('private-wrong-password', json_encode($_SESSION));
    }

    public function testRecoveryReturnsSamePublicResultForKnownAndMissingAccounts(): void
    {
        $knownResponse = $this->controller(['identity' => 'admin@admin.com'])->forgot_password();
        $knownMessage = session()->getFlashdata('message');
        Services::resetSingle('validation');
        $missingResponse = $this->controller(['identity' => 'missing@example.test'])->forgot_password();
        $this->assertSame($knownResponse->getHeaderLine('Location'), $missingResponse->getHeaderLine('Location'));
        $this->assertSame($knownMessage, session()->getFlashdata('message'));
        $this->assertStringNotContainsString('forgottenPasswordCode', json_encode($_SESSION));
    }
    public function testArrayPasswordIsRejectedBeforeLibraryCall(): void
    {
        $response = $this->controller(['identity' => 'admin@admin.com', 'password' => ['unexpected']])->login();
        $this->assertIsString($response);
        $this->assertArrayHasKey('password', service('validation')->getErrors());
        $this->assertNull(session()->get('user_id'));
    }

    public function testInactiveAndUnknownLoginUseSameError(): void
    {
        $this->assertTrue($this->model->deactivate(1));
        $this->assertFalse($this->model->login('admin@admin.com', 'password'));
        $inactive = $this->model->errorsArray(false);
        $this->model->clearErrors();
        $this->assertFalse($this->model->login('missing@example.test', 'password'));
        $this->assertSame($inactive, $this->model->errorsArray(false));
    }

}
