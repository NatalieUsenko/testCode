<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Fragment;
use App\Models\Notification;
use App\Models\NotificationType;
use Illuminate\Http\Request;
use App\Http\Requests\Notification\StoreRequest;
use Cache;

class NotificationController extends Controller
{
    /**
     * Returns notification.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNotification()
    {
        $notification = Notification::where('to_user', auth()->user()->id)
            ->where('status', Notification::STATUS_UNREAD)
            ->orderBy('id', 'DESC')
            ->first();

        $has_notification = false;
        $notifications_text = '';

        if (!is_null($notification)){
            $has_notification = true;
            $notifications_text .= '<div class="notification-msg__header">
            <span class="notification-msg__close_btn" data-id="' . $notification->id . '">×</span>
        </div>';
            $notifications_text .= '<div class="notification-msg__item">';
            if ($notification->type == 'custom'){
                $notifications_text .= Notification::substrwords($notification->text, 50);
            } else {
                $notifications_text .=  Notification::substrwords(__('notification.' . $notification->type), 50);
            }
            $notifications_text .= '<div class="read_more notification-msg__close_btn" data-id="' . $notification->id . '"><a href="'.route('notifications').'">'.__('show').'</a></div>';
            $notifications_text .= '</div>';
        }



        $response = [
            'has_notifications' => $has_notification,
            'notifications_text' => $notifications_text,
            'success' => true
        ];

        return response()->json($response);
    }

    public function updateNotification(Request $request)
    {
        $notifications = Notification::where('id', $request->data_id)
            ->update(['status' => Notification::STATUS_READ]);
        $response = [
            'success' => true,
            'notification' => $request->data_id
        ];
        return response()->json($response);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function index(Request $request)
    {
        $notifications = NotificationType::paginate(10);

        return view('admin.notification.automatic', compact('notifications'));
    }

    /**
     * @return \Illuminate\Contracts\View\View
     */
    public function create()
    {
        return view('admin.notification.create');
    }

    /**
     * @param NotificationType $notification_id
     * @return \Illuminate\Contracts\View\View
     */
    public function edit($notification_id)
    {
        $notification = NotificationType::whereId($notification_id)->get()->first();
        return view('admin.notification.edit', compact('notification'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StoreRequest $request)
    {
        $data = $request->validated();

        Notification::create([
            'from' => 'admin',
            'to_user' => $data['user_id'],
            'text' => $data['text'],
            'type' => 'custom',
            'add_id' => 0,
            'status' => Notification::STATUS_UNREAD
        ]);

        return redirect()->route('custom.index')->with('success', __('notification.success_1'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param NotificationType $notification
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, NotificationType $notification)
    {
        if (isset($request->active)) {
            $active = true;
        } else {
            $active = false;
        }
        NotificationType::where('name', $notification->name)
            ->update([
                'active' => $active
            ]);
        $fragment = Fragment::where('key', 'notification.' . $notification->name)->first();
        $fragment->setTranslation('text', 'ru', $request->text_ru);
        $fragment->setTranslation('text', 'en', $request->text_en);
        $fragment->save();

        Cache::forget("locale.fragments.ru.notification");
        Cache::forget("locale.fragments.en.notification");
        return redirect()->route('notification.index')->with('success', __('notification.success_2'));
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function custom(Request $request)
    {
        $notifications = Notification::with(['user'])
            ->where('notifications.type', 'custom')
            ->orderBy('notifications.id', 'DESC')
            ->paginate(10);

        return view('admin.notification.custom', compact('notifications'));
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\View\View
     */
    public function getPage(Request $request)
    {
        $notifications = Notification::where('to_user', auth()->user()->id)
            ->orderBy('id', 'DESC')
            ->paginate(10);

        return view('page.notifications', compact('notifications'));
    }

    /**
     * Returns notifications.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNotifications(Request $request)
    {
        $start_date = Carbon::parse(date('Y-m-d H:i:s', $request->startDate), auth()->user()->getTimezone())->tz('UTC')->toDateTimeString();
        $end_date = Carbon::parse(date('Y-m-d H:i:s', $request->endDate), auth()->user()->getTimezone())->tz('UTC')->toDateTimeString();
        $notifications = Notification::where('to_user', auth()->user()->id)
            ->whereBetween('created_at', [$start_date, $end_date])
            ->orderBy('id', 'DESC')
            ->paginate(10);

        $response['paginate']['current_page'] = $notifications->currentPage();
        $response['paginate']['total'] = $notifications->total();
        $response['paginate']['last_page'] = $notifications->lastPage();
        $response['paginate']['start_date'] = $start_date;
        $response['paginate']['end_date'] = $end_date;
        foreach ( $notifications as $key => $notification){
            $response['items'][$key]['id'] = $notification->id;
            $response['items'][$key]['status'] = $notification->status;
            if ( $notification->type == 'custom' ){
                $response['items'][$key]['text'] = $notification->text;
            } elseif ( ($notification->type == 'bonus_received') | ($notification->type == 'bonus_deducted') ){
                $response['items'][$key]['text'] = __('notification.'.$notification->type, ['bonus' => $notification->text]);
            } else {
                $response['items'][$key]['text'] = __('notification.'.$notification->type);
            }
            $response['items'][$key]['date'] = $notification->created_at->setTimezone(auth()->user()->getTimezone())->format("d.m.y");
        }

        return response()->json($response);
    }

}

