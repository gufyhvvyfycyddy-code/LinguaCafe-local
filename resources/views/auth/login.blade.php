@extends('layouts.user')
@section('content')
    <layout 
        :_user-count="{{ $userCount }}"
        :_setup-mode="{{ $setupMode ? 'true' : 'false' }}"
        :theme-settings="{}"
        _selected-language="english"
    ></layout>
@endsection
