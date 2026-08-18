<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Services\GoalService;
use App\Services\UserService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

// request classes
use App\Http\Requests\Users\CreateUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Requests\Users\UpdatePasswordRequest;
use App\Http\Requests\Users\AuthenticateUserRequest;

class UserController extends Controller {
    private $userService;

    public function __construct(UserService $userService) {
        $this->userService = $userService;

    }

    public function isUserPasswordChanged() 
    {
        $passwordChanged = Auth::user()->password_changed;
        return response()->json($passwordChanged, 200);
    }

    public function getUsers() 
    {
        $userId = Auth::user()->id;

        try {
            $users = $this->userService->getUsers($userId);
        } catch(\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json($users, 200);
    }

    public function showLoginForm() 
    {
        if (Auth::check()) {
            return redirect()->intended('/');
        }

        return $this->showPublicUserPage(false);
    }

    public function showSetupForm()
    {
        return $this->showPublicUserPage(true);
    }

    public function showRegisterForm()
    {
        if (Auth::check()) {
            return redirect()->intended('/');
        }

        return $this->showPublicUserPage(false, true);
    }

    private function showPublicUserPage(bool $setupMode, bool $registerMode = false)
    {
        $userCount = User::count();
        $theme = $_COOKIE['theme'] ?? 'light';

        return view('auth.login', [
            'userCount' => $userCount,
            'userUuid' => '',
            'theme' => $theme,
            'setupMode' => $setupMode,
            'registerMode' => $registerMode,
            'allowWebRegister' => config('linguacafe.allow_web_register'),
        ]);
    }
    
    public function authenticateUser(AuthenticateUserRequest $request) 
    {
        $email = $request->post('email');
        $password = $request->post('password');

        if (Auth::attempt([
            'email' => $email,
            'password' => $password,
        ])) {
            $request->session()->regenerate();
            Auth::logoutOtherDevices($password);
 
            return response()->json('User has been logged in successfully.', 200);
        } else {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'The email or password is incorrect.',
                ],
            ], 401);
        }
    }

    public function updatePassword(UpdatePasswordRequest $request) 
    {
        $user = Auth::user();
        $password = $request->post('password');

        try {
            $this->userService->updatePassword($user, $password);
        } catch(\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('Password has been updated successfully.', 200);
    }

    public function createUser(CreateUserRequest $request) 
    {
        $userCount = User::count();
        $name = $request->post('name');
        $email = $request->post('email');
        $password = $request->post('password');
        $allowPublicRegistration = (bool) config('linguacafe.allow_web_register');
        $isPublicRegistration = !Auth::check() && $userCount !== 0 && $allowPublicRegistration;
        $isAdmin = $userCount === 0 ? true : $request->post('isAdmin');
        $passwordChanged = $userCount === 0;

        if (Auth::check() && !((bool) Auth::user()->is_admin)) {
            abort(403, 'Only administrators can create users while signed in.');
        }

        // If this is the first user, it can be created without any authorization.
        if (!Auth::check() && $userCount !== 0 && !$allowPublicRegistration) {
            abort(401, 'Not authorized to create a user.');
        }

        if ($isPublicRegistration) {
            $isAdmin = false;
            $passwordChanged = true;
        }

        try {
            $this->userService->createUser($name, $email, $password, $isAdmin, $passwordChanged);
        } catch(\Exception $e) {
            abort(500, $e->getMessage());
        }

        return response()->json('User has been created successfully.', 200);
    }

    public function updateUser(UpdateUserRequest $request) 
    {
        $userId = $request->post('userId');
        $name = $request->post('name');
        $email = $request->post('email');
        $isAdmin = $request->post('isAdmin');

        try {
            $this->userService->updateUser($userId, $name, $email, $isAdmin);
        } catch (\DomainException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'LAST_ADMIN_REQUIRED',
                    'message' => '系统必须至少保留一个管理员账号。',
                ],
            ], 409);
        } catch (\Throwable $exception) {
            abort(500, 'User update failed.');
        }

        return response()->json('User has been updated successfully.', 200);
    }

    public function deleteUserLanguageData($language) 
    {
        $userId = Auth::user()->id;
        $language = mb_strtolower(trim((string) $language), 'UTF-8');

        if ($language !== 'english') {
            return response()->json([
                'error' => [
                    'code' => 'ENGLISH_ONLY_LANGUAGE_DATA',
                    'message' => 'Ordinary study-data deletion is limited to English.',
                ],
            ], 409);
        }

        try {
            $this->userService->deleteUserLanguageData($userId, 'english');
        } catch(\Exception $e) {
            abort(500, $e->getMessage());
        }
        
        return response()->json('English study data has been deleted successfully.', 200);
    }
}
