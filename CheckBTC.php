<?php

namespace App\Services;

use App\Http\Controllers\Admin\WalletController;
use App\Models\Fund;
use App\Models\Notification;
use App\Models\TradingType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletInfo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckBTC
{
    /**
     * Run generator.
     *
     * @return null
     */
    public function run()
    {
        $refills_confirmed =[];
        //Step 1. Get BTC refills.
        $refills = Fund::select('id', 'user_id','trading_type', 'created_at', 'status')
            ->where('type', Fund::TYPE_REFILL)
            ->where('method', Fund::METHOD_WALLETS)
            ->where('wallet_type_id', 1)
            ->where('status', Fund::STATUS_PROCESSING)
            ->get();

        //Step 2. Get and update info by wallet
        foreach ($refills as $refill) {
            $wallet = Wallet::with('users', 'walletInfo')
                ->whereHas('users', function ($query) use ($refill) {
                    $query->where('users.id', '=', $refill->user_id);
                })
                ->where('wallet_type_id', 1)
                ->first();

            if (Cache::has('blocked_wallet_'.$wallet->id)) {
                continue;
            }
            Cache::put('processing_wallet_id', $wallet->id);

            if (empty($wallet->walletInfo) || empty($wallet->walletInfo->info)){
                $startState = WalletController::blockchainInfo($wallet, $refill->created_at);
                if (empty($startState['error'])){
                    $wallet->walletInfo = WalletInfo::where('wallet_id', $wallet->id)->first();
                } else {
                    continue;
                }
            }

            $walletInfo = json_decode($wallet->walletInfo->info, true);
            $walletTransaction = json_decode($wallet->walletInfo->transactions,true);

            DB::beginTransaction();
            try {
                $customer = User::findOrFail($refill->user_id);
                if ( $walletInfo['not_processed_txn']>0 ){
                    foreach ($walletTransaction as $key => $transaction) {
                        if (!$transaction['processed']){
                            $confirmations = $transaction['confirmations'];
                            if ($confirmations <= 2){
                                $transaction['confirmations'] = WalletController::getConfirmations($transaction['hash']);
                            }

                            if ( $confirmations > 2 ){
                                if (!in_array($refill, $refills_confirmed)) {
                                    $transaction = WalletController::refillConfirmation($transaction, $refill, $customer);
                                    $refills_confirmed[] = $refill;
                                    if ($transaction['processed']){
                                        $walletInfo['not_processed_txn'] = $walletInfo['not_processed_txn']-1;
                                    }
                                }
                            }
                        }
                        $walletTransaction[$key] = $transaction;
                    }

                    //print_r('From BD '."\n");
                    WalletInfo::updateOrCreate(
                        ['wallet_id' => $wallet->id],
                        ['transactions' => json_encode($walletTransaction), 'info' => json_encode($walletInfo)]
                    );
                } else {
                    $data = WalletController::getBlockchainInfo($wallet);
                    $transactions_cache = [];
                    $notProcessedTxn = 0;
                    if (empty($data['error'])){
                        foreach ($walletTransaction as  $transaction){
                            $transactions_cache[$transaction['hash']] = $transaction;
                        }
                        unset($transaction);

                        foreach ($data['transaction_list'] as $key => $transaction){
                            if (isset($transactions_cache[$transaction['hash']])) {
                                if (!$transactions_cache[$transaction['hash']]['processed']) {
                                    if (!in_array($refill, $refills_confirmed)) {
                                        if ($transaction['confirmations'] > 2) {
                                            $transaction = WalletController::refillConfirmation($transaction, $refill, $customer);
                                            $refills_confirmed[] = $refill;
                                            if ($transaction['processed']){
                                                $walletInfo['not_processed_txn'] = $walletInfo['not_processed_txn'] - 1;
                                            }
                                        }
                                    }
                                    $transaction['processed'] = $transactions_cache[$transaction['hash']]['processed'];
                                    $transaction['rates'] = $transactions_cache[$transaction['hash']]['rates'];
                                    $data['transaction_list'][$key] = array_merge($transactions_cache[$transaction['hash']], $transaction);
                                } else {
                                    $data['transaction_list'][$key] = $transactions_cache[$transaction['hash']];
                                }
                                unset($transactions_cache[$transaction['hash']]);
                            } else {
                                if ($transaction['amount'] < 0) {
                                    $transaction['processed'] = true;
                                } else {
                                    if ( $transaction['confirmations'] > 2 ){
                                        if (!in_array($refill, $refills_confirmed)) {
                                            $transaction = WalletController::refillConfirmation($transaction, $refill, $customer);
                                            $refills_confirmed[] = $refill;
                                        }
                                    } else {
                                        $transaction['processed'] = false;
                                        $notProcessedTxn = $notProcessedTxn+1;
                                    }
                                }
                                $data['transaction_list'][$key] = $transaction;
                            }
                        }
                        $data['info']['not_processed_txn'] = max($notProcessedTxn, 0);
                        $data['info'] = array_merge($walletInfo, $data['info']);

                    //print_r($wallet->id."\n");
                        WalletInfo::updateOrCreate(
                            ['wallet_id' => $wallet->id],
                            ['transactions' => json_encode(empty($transactions_cache)?$data['transaction_list']:array_merge($data['transaction_list'], $transactions_cache)),
                                'info' => json_encode($data['info'])]
                        );

                    }
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e->getMessage());

                throw new \Exception(__('error'));
            }
            Cache::forget('processing_wallet_id');
            sleep(10);
        }

//      Step 3. Create notifications for confirmed refills.
        $notifications = [];
        foreach ($refills_confirmed as $refill) {
            $notifications[] = [
                'from' => 'cron',
                'to_user' => $refill->user_id,
                'text' => '',
                'type' => 'transaction_success',
                'add_id' => $refill->id,
                'status' => Notification::STATUS_UNREAD,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Notification::insert($notifications);
    }

}
