<?php

namespace App\Http\Controllers;

use App\DIDAuthRequest;
use App\User;
use DateTime;
use Illuminate\Http\Request;
use Elliptic\EC;
use Illuminate\Support\Facades\Auth;

class ELAAuthController extends Controller
{
    /**
     * Request from the AJAX side that checks whether or not we've received an authentication from
     * the phone/wallet app
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function checkElaAuth(Request $request)
    {
        // First dig up the state variable (the long random number we generated) for the current
        // session. If we can't find it, then the user hasn't invoked a login or registration action yet.
        $state = $request->session()->get('elaState', false);
        if (!$state) {
            return response()->json(false, 404);
        }

        // With our state number, see if our record exists in the database, has been verified by the phone
        // and hasn't expired.
        $date = new DateTime();
        $date->modify('-1 minutes');
        $token = DIDAuthRequest::where([
            ['state','=',$state],
            ['data->auth','=',true],
            ['created_at', '>=', $date->format('Y-m-d H:i:s')]
        ])->first();
        if (!$token) {
            return response()->json(['message'=>'Token not found'], 404);
        }

        // Shove the record into our session so we can relay it to the AJAX frontend when we're ready
        $request->session()->put('elaDidInfo', $token);

        // If the user belonging to this DID exists already in our system, treat it as a login
        // otherwise, toss them to the registration flow.
        $user = User::where('did', $token->data['DID'])->first();
        if (!$user) {
            $redirect = '/register/elastos/complete';
        } else {
            $redirect = '/auth/elastos/complete';
        }

        return response()->json(['redirect' => $redirect]);
    }

    /**
     * This endpoint is hit by the frontend browser after a successful authentication request if the user/did combo
     * exists in the database already.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function completeAuth(Request $request)
    {
        $didInfo = $request->session()->get('elaDidInfo', false);
        if (!$didInfo)
            return redirect('/');
        $user = User::where('did', $didInfo->data['DID'])->first();
        if (!$user)
            return redirect('/');
        DIDAuthRequest::where('state', $didInfo->state)->delete();
        Auth::login($user);
        $request->session()->remove('elaDidInfo');
        return redirect('/home');
    }

    /**
     * This endpoint is hit by the frontend browser after a successful authentication request by the phone if the
     * user/did combo does not exist in the database. We toss up a registration completion form.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Illuminate\Routing\Redirector
     */
    public function completeRegistration(Request $request)
    {
        // If we haven't gotten the DID blob back from the phone yet, then they haven't authenticated
        if (!$request->session()->get('elaDidInfo', false)) {
            return redirect('/register/elastos');
        }

        // Display registration form with junk prefilled
        return response()->view('auth.register', [
            'didInfo' => $request->session()->get('elaDidInfo')
        ]);
    }

    /**
     * Generates a QR code and signed request for the user to authenticate or register via DID on
     * their Elaphant wallet
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Exception
     */
    public function authOrRegister(Request $request)
    {
        // Snag our local application configuration variables for signing the requests
        $privateKey = env('ELA_PRIVATE_KEY');
        $publicKey = env('ELA_PUBLIC_KEY');
        $appId = env('ELA_APP_ID');
        $did = env('ELA_DID');
        $appName = env('ELA_APP_NAME');

        // Sign the app ID with the private key and snag a hex string
        $ec = new EC('p256');
        $priv = $ec->keyFromPrivate($privateKey);
        $sig = $priv->sign($appId)->toDER('hex');

        // Generate a random number for state tracking and save it to our session so the AJAX frontend
        // can reference it
        $random = rand(10000000000,999999999999);
        $request->session()->put('elaState', $random);

        $urlParams = [
            'CallbackUrl'   => env('ELA_CALLBACK'),
            'Description'   => 'Elastos DID Authentication',
            'AppID'         => $appId,
            'PublicKey'     => $publicKey,
            'Signature'     => $sig,
            'DID'           => $did,
            'RandomNumber'  => $random,
            'AppName'       => $appName,
            'RequestInfo'   => 'Nickname,Email'
        ];

        $url = 'elaphant://identity?' . http_build_query($urlParams);

        // Save the random number to the database so we can reference it later.
        $token = new DIDAuthRequest(['state' => $random, 'data' => ['auth' => false]]);
        $token->save();

        // Purge old requests just for housekeeping. Anything older than 2 minutes is definitely expired
        // though we usually cap at one minute
        $date = new DateTime();
        $date->modify('-2 minutes');
        DIDAuthRequest::where('created_at', '<=', $date->format('Y-m-d H:i:s'))->delete();

        // Load the QR view, show the code and auth instructions
        return view('auth.elastos', [
            'scanUrl' => $url
        ]);
    }

    /**
     * This is the endpoint that the phone hits. Here we check the signatures included in the payload and
     * resurrect the state from the database based on the RandomNumber. If things look good, we update the database
     * state record so that the AJAX side waiting for auth is made aware that they're all clear.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @throws \Exception
     */
    public function didCallback(Request $request)
    {
        // Decode the 'Data' and 'Sign' attributes that Elaphant includes
        $data = json_decode($request->input('Data'), true);
        $signature = $request->input('Sign');
        $clientPublicKey = $data['PublicKey'];

        // The signatures are based on a sha256 hash of the data, which is itself a prettified JSON string
        $msg = hash('sha256', $request->input('Data'));

        // Verify signature using the above pub key and data.
        $ec = new EC('p256');
        $key = $ec->keyFromPublic($clientPublicKey, 'hex');
        // In order for the signatures to work, we need to split the Sign payload in half into r and s components
        // This tells us that the information wasn't tampered with and corresponds to the public key and DID given
        $r = substr($signature, 0, 64);
        $s = substr($signature, 64, 64);
        if ($ec->verify($msg, ['r'=> $r, 's'=>$s], $key)) {
            // If the signature is good, make sure the state record in the database is less than a minute old.
            $date = new DateTime();
            $date->modify('-1 minutes');
            $auth = DIDAuthRequest::where([
                ['state', '=', $data['RandomNumber']],
                ['created_at', '>=', $date->format('Y-m-d H:i:s')]
            ])->first();

            // If no state record is found, toss a 401 back to the phone
            if (!$auth) {
                return response(['message' => 'Unauthorized'], 401);
            }

            // Otherwise, update the state record with the given DID and merge the current DB data with what
            // the phone gave us.
            $authData = $auth->data;
            $authData['did'] = $data['DID'];
            $authData['auth'] = true;
            $authData = array_merge($authData, $data);
            $auth->data = $authData;
            $auth->update();
        } else {
            // Crypto verification failed. Something could've corrupted, or the data could've been tampered with.
            return response(['message' => 'Unauthorized'], 401);
        }
    }
}
