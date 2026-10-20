<?php
/**
 * SARMAD Attendance - web authentication controller.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Redirect;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\AuthService;
use App\Support\Url;

final class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function showLogin(Request $request): Response
    {
        if (Session::isUser()) {
            return Redirect::response(AuthService::defaultRedirectFor((string) Session::role()));
        }

        return Response::html(View::render('auth/login', [
            'title'    => 'ورود به سامانه',
            'intended' => (string) $request->input('next', ''),
            'error'    => Session::getFlash('error'),
            'success'  => Session::getFlash('success'),
        ], 'layouts/blank'));
    }

    public function login(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string|max:190',
            'password'   => 'required|string|max:200',
        ], [
            'identifier' => 'نام کاربری یا ایمیل',
            'password'   => 'رمز عبور',
        ]);

        if ($validator->fails()) {
            Session::flash('error', $validator->firstError());

            return Response::redirect(Url::to('/login'));
        }

        $intended = (string) $request->input('next', '');

        $result = $this->auth->attempt(
            (string) $request->input('identifier', ''),
            (string) $request->input('password', ''),
            true,
            $intended
        );

        if ($result['result'] !== AuthService::RESULT_OK) {
            Session::flash('error', $result['message']);

            return Response::redirect(Url::to('/login'));
        }

        Session::flash('success', $result['message']);

        return Response::redirect($result['redirect']);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();

        Session::flash('success', 'با موفقیت از سامانه خارج شدید.');

        return Response::redirect(Url::to('/login'));
    }

    public function showPassword(Request $request): Response
    {
        return Response::html(View::render('auth/password', [
            'title'   => 'تغییر رمز عبور',
            'user'    => Session::user(),
            'error'   => Session::getFlash('error'),
            'success' => Session::getFlash('success'),
        ], 'layouts/app'));
    }

    public function updatePassword(Request $request): Response
    {
        $userId = (int) (Session::userId() ?? 0);

        $result = $this->auth->changePassword(
            $userId,
            (string) $request->input('current_password', ''),
            (string) $request->input('new_password', ''),
            (string) $request->input('new_password_confirmation', '')
        );

        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return Response::redirect(Url::to('/account/password'));
    }
}
