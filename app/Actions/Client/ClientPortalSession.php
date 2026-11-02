<?php

namespace App\Actions\Client;

use App\Models\Client;
use Illuminate\Contracts\Session\Session;

/**
 * The clients signed in to the portal on one browser. Several clients can be signed in
 * at once (for example a family member checking more than one case on a shared phone);
 * each portal URL names its client, so tabs for different clients never mix.
 *
 * This is separate from the superadmin login: a client never becomes a signed-in user.
 */
class ClientPortalSession
{
    private const KEY = 'client_ids';

    public function __construct(private Session $session) {}

    public function isSignedIn(Client $client): bool
    {
        return in_array($client->id, $this->clientIds(), true);
    }

    /**
     * Sign a client in after the right PIN, with a new session ID against session fixation.
     */
    public function signIn(Client $client): void
    {
        $this->session->regenerate();
        $this->session->put(self::KEY, array_values(array_unique([...$this->clientIds(), $client->id])));
    }

    /**
     * Sign out only this client; other clients and a superadmin on the same browser stay signed in.
     */
    public function signOut(Client $client): void
    {
        $this->session->put(self::KEY, array_values(array_diff($this->clientIds(), [$client->id])));
        $this->session->regenerate();
    }

    /**
     * @return list<int>
     */
    private function clientIds(): array
    {
        return array_values(array_filter((array) $this->session->get(self::KEY, []), is_int(...)));
    }
}
