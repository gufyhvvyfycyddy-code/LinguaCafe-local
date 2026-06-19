@extends('layouts.user')
@section('content')
    <layout 
        :_user-count="{{ $userCount }}"
        :_setup-mode="{{ $setupMode ? 'true' : 'false' }}"
        :_register-mode="{{ $registerMode ? 'true' : 'false' }}"
        :_allow-web-register="{{ $allowWebRegister ? 'true' : 'false' }}"
        :theme-settings="{}"
        _selected-language="english"
    ></layout>
@endsection
