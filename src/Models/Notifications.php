<?php

namespace ZamApps\ZamInterface\Models;

class Notifications extends \Illuminate\Database\Eloquent\Model
{

    /**
     * Do not use timestamps.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The database table that the model is connected to.
     *
     * @var string
     */
    protected $table = 'notifications';

    /**
     * The attributes that are mass-assignable.
     *
     * @var array
     */
    protected $fillable = [
        'notification',
        'description',
        'message_body',
        'subject',
        'parameters'
    ];

    /**
     * Each Notification can have many NotificationSubscriptions.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function notificationSubscriptions()
    {
        return $this->hasMany('App\NotificationSubscriptions', 'notification_id');
    }

    /**
     * Return the notificationSubscriptions that belong to this Notification with the User eager-loaded.
     *
     * @return mixed
     */
    public function subscribers()
    {
        return $this->notificationSubscriptions()->with('user_info');
    }
}
