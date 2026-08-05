<?php

namespace App\Controllers;

use App\Filters\LocaleFilter;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

/**
 * The header language switcher.
 *
 * Available to guests as well as signed-in users — the login page is the first
 * thing anyone sees, so it is the first place a language choice is worth having.
 */
class Locale extends BaseController
{
    /**
     * Switch language.
     *
     * The submitted value is checked against `Config\App::$supportedLocales` and
     * discarded otherwise. That is not defensive padding: CI4 interpolates the
     * locale into a filesystem path when loading language files, so an unchecked
     * value from a form is a path-traversal primitive.
     */
    public function switch(): ResponseInterface
    {
        // This route deliberately has no `authFilter` (guests switch language on
        // the login page), and `AuthFilter` is what normally loads this helper —
        // so it has to be loaded here or `current_user_id()` is undefined.
        helper('auth');

        $requested = trim((string) $this->request->getPost('locale'));
        $supported = config(App::class)->supportedLocales;

        if (! in_array($requested, $supported, true)) {
            return $this->jsonFailed('That language is not available.');
        }

        session()->set(LocaleFilter::SESSION_KEY, $requested);

        // Persist it for a signed-in user so the choice survives a new session and
        // follows them to another browser. `userWorking` is refreshed too, or the
        // filter would keep reading the stale value for the rest of this session.
        $userId = current_user_id();

        if ($userId > 0 && ! is_impersonating()) {
            model(UserModel::class)->update($userId, ['language' => $requested]);

            $user = session('userWorking');

            if (is_object($user)) {
                $user->language = $requested;
                session()->set('userWorking', $user);
            }
        }

        return $this->jsonSuccess('Language updated.', ['locale' => $requested]);
    }
}
