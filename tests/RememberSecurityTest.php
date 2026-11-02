<?php

namespace IonAuth\Tests;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use IonAuth\Libraries\IonAuth;
use IonAuth\Models\IonAuthModel;

class RememberSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait {
        setUpDatabase as protected traitSetUpDatabase;
    }

    protected $namespace = 'IonAuth';
    protected $seed = 'IonAuth\\Database\\Seeds\\IonAuthSeeder';
    private IonAuthModel $model;
    private IncomingRequest $request;

    protected function setUpDatabase()
    {
        $config = config('IonAuth');
        $config->databaseGroupName = 'tests';
        $config->bcryptDefaultCost = 4; // Test fixtures only.
        $config->bcryptAdminCost = 4;
        $config->rememberUsers = true;
        $config->userExtendonLogin = false;
        $config->identity = 'email';
        $this->traitSetUpDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->request = new IncomingRequest(config('App'), new URI('https://localhost/'), null, new UserAgent());
        $this->request->setGlobal('server', ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on']);
        Services::injectMock('request', $this->request);
        $this->model = new IonAuthModel();
        $this->assertSame('SQLite3', $this->model->db()->DBDriver);
        $this->assertSame(':memory:', $this->model->db()->database);
    }

    private function issueToken(): string
    {
        $this->assertTrue($this->model->rememberUser('admin@admin.com'));
        return service('response')->getCookie(config('IonAuth')->rememberCookieName)->getValue();
    }

    private function replay(string $token): bool
    {
        $_SESSION = [];
        $this->request->setGlobal('cookie', [config('IonAuth')->rememberCookieName => $token]);
        return $this->model->loginRememberedUser();
    }

    public function testValidTokenAuthenticates(): void
    {
        $this->assertTrue($this->replay($this->issueToken()));
        $this->assertSame(1, (int) session()->get('user_id'));
    }

    public function testReplacementRejectsPreviousToken(): void
    {
        $old = $this->issueToken();
        $new = $this->issueToken();
        $this->assertFalse($this->replay($old));
        $this->assertTrue($this->replay($new));
    }

    public function testProfilePasswordUpdateRevokesTokenAndRecoveryCode(): void
    {
        $token = $this->issueToken();
        $recovery = $this->model->forgottenPassword('admin@admin.com');
        $this->assertNotFalse($recovery);
        $this->assertTrue($this->model->update(1, ['password' => 'new-password-with-spaces  ']));
        $this->assertFalse($this->replay($token));
        $this->assertFalse($this->model->getUserByForgottenPasswordCode($recovery));
        $this->assertTrue($this->model->login('admin@admin.com', 'new-password-with-spaces  '));
    }

    public function testEmptyPasswordUpdatePreservesToken(): void
    {
        $token = $this->issueToken();
        $this->assertTrue($this->model->update(1, ['password' => '', 'first_name' => 'Edited']));
        $this->assertTrue($this->replay($token));
    }

    public function testResetPasswordRevokesToken(): void
    {
        $token = $this->issueToken();
        $this->assertTrue($this->model->resetPassword('admin@admin.com', 'new-test-password'));
        $this->assertFalse($this->replay($token));
    }

    public function testDisabledRememberBlocksModelAndLibrary(): void
    {
        $token = $this->issueToken();
        config('IonAuth')->rememberUsers = false;
        $this->assertFalse($this->model->rememberUser('admin@admin.com'));
        $this->assertFalse($this->replay($token));
        $this->assertFalse((new IonAuth())->loggedIn());
    }

    public function testLogoutRevokesCorrectIdentity(): void
    {
        $token = $this->issueToken();
        $this->assertTrue($this->replay($token));
        $this->assertTrue((new IonAuth())->logout());
        $this->assertNull(session()->get('user_id'));
        $this->assertFalse($this->replay($token));
    }

    public function testInactiveUserCannotReplayToken(): void
    {
        $token = $this->issueToken();
        $this->assertTrue($this->model->deactivate(1));
        $this->assertFalse($this->replay($token));
    }

    public function testMalformedCookieCannotAuthenticate(): void
    {
        $this->assertFalse($this->replay('invalid'));
    }

    public function testRememberCookieAlwaysHttpOnly(): void
    {
        config('Cookie')->httponly = false;
        $this->issueToken();
        $cookie = service('response')->getCookie(config('IonAuth')->rememberCookieName);
        $this->assertTrue($cookie->isHTTPOnly());
    }

    public function testLogoutSupportsUsernameIdentity(): void
    {
        config('IonAuth')->identity = 'username';
        $this->model = new IonAuthModel();
        $this->assertTrue($this->model->rememberUser('administrator'));
        $token = service('response')->getCookie(config('IonAuth')->rememberCookieName)->getValue();
        $this->assertTrue($this->replay($token));
        $this->assertTrue((new IonAuth())->logout());
        $this->assertFalse($this->replay($token));
    }

    public function testClearedValidatorWithRetainedSelectorFailsClosed(): void
    {
        $token = $this->issueToken();
        $this->model->db()->table(config('IonAuth')->tables['users'])->where('id', 1)->update(['remember_code' => null]);
        $this->assertFalse($this->replay($token));
    }

}
