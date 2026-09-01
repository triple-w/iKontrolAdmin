<?php
namespace App\Http\Controllers\Admin; use App\Http\Controllers\Controller; use App\Models\{Client,IkontrolInstance};
class DashboardController extends Controller { public function __invoke(){return view('admin.dashboard',['stats'=>['clients'=>Client::whereActive(true)->count(),'instances'=>IkontrolInstance::count(),'connected'=>IkontrolInstance::where('last_connection_status','CONNECTED')->count(),'errors'=>IkontrolInstance::where('last_connection_status','ERROR')->count()],'instances'=>IkontrolInstance::with('client')->latest()->limit(10)->get()]);} }
