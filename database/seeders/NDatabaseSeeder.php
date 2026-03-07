<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\New\{Plan, Subscription, Devices, ActiveStream, StreamEvent};
use Carbon\Carbon;

class NDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $plans = Plan::insert([
            ['name'=>'Kar 1','price'=>49,'duration_days'=>30,'device_limit_mobile'=>1,'device_limit_browser'=>1,'device_limit_tv'=>1,'quality'=>'SD'],
            ['name'=>'Thla 1','price'=>99,'duration_days'=>30,'device_limit_mobile'=>2,'device_limit_browser'=>2,'device_limit_tv'=>1,'quality'=>'HD'],
            ['name'=>'Thla 6','price'=>499,'duration_days'=>180,'device_limit_mobile'=>3,'device_limit_browser'=>3,'device_limit_tv'=>2,'quality'=>'FULL_HD'],
            ['name'=>'Kum 1','price'=>899,'duration_days'=>365,'device_limit_mobile'=>4,'device_limit_browser'=>4,'device_limit_tv'=>3,'quality'=>'4K']
        ]);

        $sub1 = Subscription::create([
            'user_id'=>1,'plan_id'=>4,'start_at'=>now(),'end_at'=>now()->addYear(),'is_active'=>true,'renewed_by'=>1
        ]);

        $sub2 = Subscription::create([
            'user_id'=>2,'plan_id'=>2,'start_at'=>now(),'end_at'=>now()->addDays(30),'is_active'=>true,'renewed_by'=>2
        ]);

        $dev1 = Devices::create(['subscription_id'=>$sub1->id,'user_id'=>1,'device_name'=>'iPhone 15 Pro','device_type'=>'mobile','device_token'=>'DEV-TOK-MOB-111','is_owner_device'=>true]);
        $dev2 = Devices::create(['subscription_id'=>$sub1->id,'user_id'=>1,'device_name'=>'MacBook Pro 14','device_type'=>'browser','device_token'=>'DEV-TOK-BRO-112']);
        $dev3 = Devices::create(['subscription_id'=>$sub1->id,'user_id'=>1,'device_name'=>'Samsung TV 55"','device_type'=>'tv','device_token'=>'DEV-TOK-TV-113']);
        $dev4 = Devices::create(['subscription_id'=>$sub2->id,'user_id'=>2,'device_name'=>'Redmi Note 12','device_type'=>'mobile','device_token'=>'DEV-TOK-MOB-221','is_owner_device'=>true]);

        ActiveStream::create(['subscription_id'=>$sub1->id,'device_id'=>$dev1->id,'device_type'=>'mobile','stream_token'=>'STREAM-TOK-AAA111','status'=>'active']);
        ActiveStream::create(['subscription_id'=>$sub1->id,'device_id'=>$dev2->id,'device_type'=>'browser','stream_token'=>'STREAM-TOK-BBB222','status'=>'active']);
        ActiveStream::create(['subscription_id'=>$sub2->id,'device_id'=>$dev4->id,'device_type'=>'mobile','stream_token'=>'STREAM-TOK-CCC333','status'=>'active']);

        StreamEvent::create(['subscription_id'=>$sub1->id,'device_id'=>$dev1->id,'event_type'=>'start','event_data'=>['ip'=>'192.168.0.10']]);
        StreamEvent::create(['subscription_id'=>$sub1->id,'device_id'=>$dev2->id,'event_type'=>'ping','event_data'=>['ts'=>time()]]);
        StreamEvent::create(['subscription_id'=>$sub2->id,'device_id'=>$dev4->id,'event_type'=>'start','event_data'=>['ip'=>'192.168.0.20']]);
    }
}