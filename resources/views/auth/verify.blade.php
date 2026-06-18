@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">验证邮箱地址</div>

                <div class="card-body">
                    @if (session('resent'))
                        <div class="alert alert-success" role="alert">
                            新的验证链接已发送到你的邮箱。
                        </div>
                    @endif

                    继续前，请检查邮箱中的验证链接。
                    如果没有收到邮件，
                    <form class="d-inline" method="POST" action="{{ route('verification.resend') }}">
                        @csrf
                        <button type="submit" class="btn btn-link p-0 m-0 align-baseline">点击这里重新发送</button>。
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
