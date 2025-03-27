<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\MerchentSettlementWallet;
use App\Models\Merchent;
use Illuminate\Support\Facades\Http;

use Illuminate\Support\Facades\Log;


class ApiController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
    public function Api(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'validationTraceId' => 'required|string|min:1|max:20',
                'CustMob' => 'sometimes|max:25',
                'CustId' => 'sometimes|digits_between:0,25',
                'TranID' => 'required|string|min:1|max:25',
                'TranAmount' => 'required|numeric|min:1.00',
                'qrString' => 'required|string|min:1|max:2048',
                'MerchantID' => 'required|string|min:1|max:20',
                // 'MerchantID' => 'required|string|size:15',
            ]);
    
        } catch (ValidationException $e) {
    
            return response()->json([
                'Status' => '05',
                'Message' => 'Unable To Process | Validation failed',
                'Errors' => $e->validator->errors(),
            ], 422);
        }
        // Check for Basic Auth credentials
        $authHeader = $request->header('Authorization');
    
        if (!$authHeader || !preg_match('/Basic\s(\S+)/', $authHeader, $matches)) {
    
            return response()->json([
                'Status' => '05',
                'Message' => 'Unable To Process | Authorization header not provided',
            ], 401);
        }
        $credentials = base64_decode($matches[1]);
        list($username, $password) = explode(':', $credentials);
    
        $user = User::where('username', $username)->first(); 
    
        if (!$user || ($password != $user->password)) {
            // if (!$user || !Hash::check($password, $user->password)) {
    
            return response()->json([
                'Status' => '05',
                'Message' => 'Unable To Process | Invalid credentials',
            ], 401);
        }

        Log::channel('reqRes')->info("Initial Request By $user with Data :", $validatedData);

        $memberCode = $user->institution_id;
        $merchentID = $validatedData['MerchantID'];
        $TranAmount = $validatedData['TranAmount'];
    
        $merchent = Merchent::where('gmid',$merchentID)->first();
    
        if(!$merchent){

        Log::channel('reqRes')->info("Merchent not Found in Merchent Table");

            return response()->json([
                'Status' => '05',
                'Message' => 'Unable To Process | Merchent not Found in our System',
            ], 401);
        }
        $type = $merchent->business_type;
        $kyc = $merchent->kym_verified_marked;
    
        if($merchent->status !== 'ENABLED'){

        Log::channel('reqRes')->info("Status is not enabled");

            return response()->json([
                'Status' => '05',
                'Message' => 'Unable To Process | Status is not enabled',
            ], 401);
        }
    
        if(($kyc === false ) && ($TranAmount > 5000)){

        Log::channel('reqRes')->info("KYC Verify Failed");

            return response()->json([
                'Status' => '05',
                'Message' => 'Unable To Process | KYC Verify Failed',
            ], 401);
        }
        $data = json_decode($type, true);
    
        if (isset($data['label']) && isset($data['value'])) {
            $label = $data['label'];
            $value = $data['value'];
            if ($label === "Personal") {
                $p2p = true; 
            } else {

            Log::channel('reqRes')->info("SUCCESS | NOT P2P");

                return response()->json([
                    'Status' => '00',
                    'Message' => 'SUCCESS | Approved',
                ], 200);
            }
        } else {

            Log::channel('reqRes')->info("Merchent Found BUT P2P(business_type) DATA NOT FOUND CONTACT ADMIN");

            return response()->json([
                'Status' => '05',
                'Message' => 'Unable To Process | CONTACT ADMIN',
            ], 404);
        }
    
        $insForMerURL = substr($merchentID, 0, 3);
    
        if($insForMerURL == 002 && $p2p === true){ // for Specific ins

            $walletDetails = MerchentSettlementWallet::where('gmid',$merchentID)->first();
    
            if(!$walletDetails){
    
                Log::channel('reqRes')->info(" P2P True WALLET NOT FOUND in wallet table CONTACT ADMIN");
    
                return response()->json([
                    'Status' => '05',
                    'Message' => 'Unable To Process | WALLET ID NOT FOUND CONTACT ADMIN',
                ], 404);
            }
        
            $walletId = $walletDetails->wallet_id;
    
            $merchantCheckResponse = Http::withHeaders([
    
                'Authorization' => 'Basic V2ViX1VzZXI6eVB6ZGVCa0NZQ1c1aEN5ZElRK2Jmdz09',
                'Content-Type' => 'application/json',
                'Module' => 'V2Vi'
            ])->post("http://i.i.i.i:pppp/api/qpay/validate/wallet", [
                'WalletId' => $walletId,
                'QrPayload' => $validatedData['qrString'],
                'MID' => $merchentID,
                'Amount' => $TranAmount
            ]);
            Log::channel('reqRes')->info("Request To $insForMerURL :", ['WalletId' => $walletId,'QrPayload' => $validatedData['qrString'],'MID' => $merchentID,'Amount' => $TranAmount]);
    
                $status = $merchantCheckResponse['Status'];
                $message = $merchantCheckResponse['Message'];
                $TranId = $merchantCheckResponse['TranId'];

                Log::channel('reqRes')->info("Response From $insForMerURL :", ['Status' => $status,'Message' => $message,'TranId' => $TranId]);

                if($status == '00'){
                    $message = 'SUCCESS | '.$message;
                }
        }else{
            $status = '00';
            $message = 'SUCCESS | Approved';

            Log::channel('reqRes')->info("SUCCESS | NOT IME PAY |");
        }
        $responseData = [
            'Status' => $status,
            'Message' => $message,
        ];
        Log::channel('reqRes')->info("Final Response END :", $responseData );

        // Return a JSON response
        return response()->json($responseData,200);
    }
}