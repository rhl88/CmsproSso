<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>账号管理</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    <style>
    .layui-form-label {
        padding: 9px 4px;
        width: unset;
    }
    .layui-input, .layui-textarea {
        width: 98%;
    }
    .avatar-area {
        text-align: center;
        padding: 20px;
    }
    .avatar-area img {
        max-width: 120px;
        border-radius: 50%;
    }
</style>
</head>
<body>
<div class="pear-container" style="padding: 10px;">
    <div class="layui-card">
        <div class="layui-card-header">个人信息</div>
        <div class="layui-card-body">
            <div class="layui-row">
                <div class="layui-col-md3 avatar-area">
                    <img src="{{ auth('web')->user()->avatar ?? asset('Admin/images/avatar.png') }}" alt="头像">
                </div>
                <div class="layui-col-md9">
                    <form class="layui-form" id="accountForm" action="">
                        <input type="hidden" name="id" value="{{ auth('web')->user()->id }}">
                        <div class="layui-form-item">
                            <label class="layui-form-label">用户名</label>
                            <div class="layui-input-block">
                                <input type="text" name="username" value="{{ auth('web')->user()->username }}" class="layui-input" disabled>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">昵称</label>
                            <div class="layui-input-block">
                                <input type="text" name="nickname" value="{{ auth('web')->user()->nickname ?? '' }}" lay-verify="required" lay-reqText="昵称不能为空" placeholder="请输入昵称" class="layui-input" autocomplete="off">
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">邮箱</label>
                            <div class="layui-input-block">
                                <input type="text" name="email" value="{{ auth('web')->user()->email ?? '' }}" placeholder="请输入邮箱" class="layui-input" autocomplete="off">
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">手机号</label>
                            <div class="layui-input-block">
                                <input type="text" name="phone" value="{{ auth('web')->user()->phone ?? '' }}" placeholder="请输入手机号" class="layui-input" autocomplete="off">
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">性别</label>
                            <div class="layui-input-block">
                                <input type="radio" name="gender" value="0" title="未知" {{ (auth('web')->user()->gender->value ?? 0) == 0 ? 'checked' : '' }}>
                                <input type="radio" name="gender" value="1" title="男" {{ (auth('web')->user()->gender->value ?? 0) == 1 ? 'checked' : '' }}>
                                <input type="radio" name="gender" value="2" title="女" {{ (auth('web')->user()->gender->value ?? 0) == 2 ? 'checked' : '' }}>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">签名</label>
                            <div class="layui-input-block">
                                <textarea name="signature" placeholder="请输入个性签名" class="layui-textarea">{{ auth('web')->user()->signature ?? '' }}</textarea>
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <button type="submit" class="layui-btn layui-btn-normal layui-btn-sm" lay-submit lay-filter="save">
                                <i class="layui-icon layui-icon-ok"></i> 保存
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="layui-card" style="margin-top: 10px;">
        <div class="layui-card-header">账号绑定</div>
        <div class="layui-card-body">
            <div style="color: #666; font-size: 13px; margin-bottom: 14px;">
                可绑定手机号、邮箱及各快捷登录方式（微信/QQ 等），绑定后可用对应方式快捷登录。
            </div>
            <button type="button" class="layui-btn layui-btn-normal layui-btn-sm sso-bind-center-open">
                <i class="layui-icon layui-icon-link"></i> 打开账号绑定
            </button>
        </div>
    </div>
</div>
<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
    layui.use(['form', 'jquery'], function() {
        var form = layui.form;
        var $ = layui.jquery;

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        });

        form.on('submit(save)', function(data) {
            $.ajax({
                url: '/api/user/profile',
                type: 'PUT',
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify(data.field),
                success: function(res) {
                    if (res.code === 0) {
                        layer.msg('保存成功', { icon: 1, time: 1000 });
                    } else {
                        layer.msg(res.message || '保存失败', { icon: 2, time: 1500 });
                    }
                },
                error: function() {
                    layer.msg('请求失败', { icon: 2, time: 1500 });
                }
            });
            return false;
        });
    });
</script>
@include('cmspro.sso::Home.partials.bind-center')
</body>
</html>