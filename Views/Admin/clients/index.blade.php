<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>客户端管理</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
</head>
<body>
<div class="pear-container">
    {{-- 接入说明 --}}
    <div class="layui-card">
        <div class="layui-card-body" style="color: #666; font-size: 13px; line-height: 1.9;">
            @if(!($is_server ?? false))
            <div style="padding: 8px 12px; margin-bottom: 10px; border-left: 3px solid #ffb800; background: #fffbe6; color: #874d00; font-size: 13px; line-height: 1.8;">
                当前站点快捷模式为「独立站」或「客户端」，客户端管理仅对快捷模式 = 服务端 生效。请在「登录设置 → 访问设置」的站点角色中切换为「服务端」。
            </div>
            @endif
            <i class="layui-icon layui-icon-tips" style="color: #1e9fff;"></i>
            客户端站点接入统一登录中转的授权地址：<code>{{ $authorize_base }}/oauth/authorize</code>，
            开放接口：<code>/api/cmspro/sso/oauth/platforms|token|userinfo|revoke</code>。
            客户端回调地址须逐行加入下方白名单，未在白名单内的地址将被拒绝。
        </div>
    </div>

    {{-- 搜索卡 --}}
    <div class="layui-card">
        <div class="layui-card-body">
            <form class="layui-form" action="">
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <input type="text" name="keyword" placeholder="应用名称 / 客户端ID" class="layui-input">
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <select name="status">
                            <option value="">全部状态</option>
                            <option value="1">启用</option>
                            <option value="0">禁用</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <button class="layui-btn layui-btn-md" lay-submit lay-filter="clients-query">
                        <i class="layui-icon layui-icon-search"></i> 查询
                    </button>
                    <button type="reset" class="layui-btn layui-btn-md layui-btn-primary">
                        <i class="layui-icon layui-icon-refresh"></i> 重置
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- 表格卡 --}}
    <div class="layui-card">
        <div class="layui-card-body">
            <table id="clients-table" lay-filter="clients-table"></table>
        </div>
    </div>

    {{-- 工具栏 --}}
    <script type="text/html" id="clients-toolbar">
        <div class="layui-btn-container">
            <button class="layui-btn layui-btn-sm" lay-event="create" data-permission="cmspro.sso.manage">
                <i class="layui-icon layui-icon-add-1"></i> 新建客户端
            </button>
        </div>
    </script>

    {{-- 状态模板 --}}
    @verbatim
    <script type="text/html" id="clientStatusTpl">
        {{# if(d.status === 1){ }}
        <span class="layui-badge layui-bg-green">启用</span>
        {{# } else { }}
        <span class="layui-badge layui-bg-gray">禁用</span>
        {{# } }}
    </script>

    <script type="text/html" id="redirectUrisTpl">
        {{# var count = (d.redirect_uris || '').split('\n').filter(function(s){ return s.trim() !== ''; }).length; }}
        <span>{{ count }} 个地址</span>
    </script>

    <script type="text/html" id="clients-bar">
        <div class="layui-btn-container">
            <a class="layui-btn layui-btn-xs" lay-event="more">操作 <i class="layui-icon layui-icon-down"></i></a>
        </div>
    </script>
    @endverbatim

    {{-- 新建/编辑弹窗 --}}
    <div id="client-form-dialog" style="display:none; padding: 20px 20px 0;">
        <form class="layui-form" lay-filter="client-form">
            <input type="hidden" name="id" value="">
            <div class="layui-form-item">
                <label class="layui-form-label"><span style="color:#ff5722;">*</span> 应用名称</label>
                <div class="layui-input-block">
                    <input type="text" name="name" placeholder="客户端站点名称，如：论坛站" class="layui-input" style="width: 320px;" maxlength="100">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label"><span style="color:#ff5722;">*</span> 回调白名单</label>
                <div class="layui-input-block">
                    <textarea name="redirect_uris" placeholder="每行一个完整 URL，如：&#10;https://bbs.example.com/auth/relay/callback" class="layui-textarea" style="width: 320px;" rows="4"></textarea>
                    <div class="layui-form-mid layui-word-aux">每行一个完整回调地址，须与客户端请求中的 redirect_uri 精确一致</div>
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">备注</label>
                <div class="layui-input-block">
                    <input type="text" name="remark" placeholder="选填" class="layui-input" style="width: 320px;" maxlength="255">
                </div>
            </div>
            <div class="layui-form-item" style="text-align: center;">
                <button type="button" class="layui-btn" id="client-form-submit">
                    <i class="layui-icon layui-icon-ok"></i> 保存
                </button>
            </div>
        </form>
    </div>
</div>

<script src="{{ asset('CmsProUi/component/layui/layui.js') }}"></script>
<script src="{{ asset('CmsProUi/component/pear/pear.js') }}"></script>
<script>
layui.use(['table', 'form', 'layer', 'jquery'], function() {
    var table = layui.table;
    var form = layui.form;
    var layer = layui.layer;
    var $ = layui.jquery;

    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        statusCode: {
            401: function () {
                layer.msg('登录已过期，请重新登录', { icon: 2, time: 1500 }, function () {
                    top.location.href = '/admin/login';
                });
            }
        }
    });

    form.render();

    // 权限按钮隐藏
    var hidePermButtons = function() {
        $('button[data-permission]').each(function() {
            if (!window.hasPermission || !window.hasPermission($(this).data('permission'))) {
                $(this).hide();
            }
        });
    };
    hidePermButtons();

    var inst = table.render({
        elem: '#clients-table',
        url: '/admin/cmspro/sso/api/clients',
        toolbar: '#clients-toolbar',
        defaultToolbar: [{
            title: '刷新',
            layEvent: 'refresh',
            icon: 'layui-icon-refresh'
        }, 'filter', 'print', 'exports'],
        cellMinWidth: 80,
        page: {
            layout: ['count', 'prev', 'page', 'next', 'limit'],
            groups: 5,
            limit: 15,
            limits: [15, 30, 50, 100]
        },
        maxHeight: ($(window).height() - 220) + 'px',
        cols: [[
            { title: 'ID', field: 'id', align: 'center', width: 70, fixed: 'left', unresize: true },
            { title: '应用名称', field: 'name', align: 'center', minWidth: 140 },
            { title: '客户端ID', field: 'client_id', align: 'center', templet: function(d) { return '<a href="javascript:;" class="copy-text" style="color:#1e9fff;cursor:pointer;" data-text="' + d.client_id + '" title="点击复制">' + d.client_id + '</a>'; } },
            { title: '回调地址', field: 'redirect_uris', align: 'center', templet: '#redirectUrisTpl' },
            { title: '状态', field: 'status', align: 'center', width: 80, templet: '#clientStatusTpl' },
            { title: '备注', field: 'remark', align: 'center', minWidth: 120, templet: function(d) { return d.remark || '<span style="color:#999;">-</span>'; } },
            { title: '创建时间', field: 'create_time', align: 'center', width: 170 },
            { title: '操作', toolbar: '#clients-bar', align: 'center', width: 100, fixed: 'right' }
        ]],
        skin: false,
        parseData: function(res) {
            return {
                "code": res.code === 0 ? 0 : 1,
                "msg": res.message || res.msg || "",
                "count": res.data ? (res.data.pagination ? res.data.pagination.total : 0) : 0,
                "data": res.data ? (res.data.items || []) : []
            };
        },
        done: function() {
            $('td [data-permission]').each(function() {
                if (!window.hasPermission || !window.hasPermission($(this).data('permission'))) {
                    $(this).hide();
                }
            });
        }
    });

    // 搜索
    form.on('submit(clients-query)', function(data) {
        inst.reload({ where: data.field, page: { curr: 1 } });
        return false;
    });

    // 工具栏事件
    table.on('toolbar(clients-table)', function(obj) {
        if (obj.event === 'create') {
            window.openForm();
        } else if (obj.event === 'refresh') {
            table.reload('clients-table');
        }
    });

    // 行工具事件
    table.on('tool(clients-table)', function(obj) {
        if (obj.event === 'more') {
            layui.dropdown.render({
                elem: this,
                show: true,
                align: 'right',
                data: [
                    { title: '编辑', id: 'edit' },
                    { title: '重置密钥', id: 'reset' },
                    { title: '启用/禁用', id: 'toggle' },
                    { title: '删除', id: 'del' }
                ],
                click: function(menudata) {
                    if (menudata.id === 'edit') {
                        window.openForm(obj.data);
                    } else if (menudata.id === 'reset') {
                        window.resetSecret(obj);
                    } else if (menudata.id === 'toggle') {
                        window.toggleStatus(obj);
                    } else if (menudata.id === 'del') {
                        window.remove(obj);
                    }
                }
            });
        }
    });

    // 新建/编辑弹窗
    window.openForm = function(client) {
        if (!window.hasPermission || !window.hasPermission('cmspro.sso.manage')) {
            layer.msg('无操作权限', { icon: 2, time: 1000 });
            return;
        }
        client = client || null;
        form.val('client-form', {
            id: client ? client.id : '',
            name: client ? client.name : '',
            redirect_uris: client ? client.redirect_uris : '',
            remark: client ? client.remark : ''
        });
        layer.open({
            type: 1,
            title: client ? '编辑客户端' : '新建客户端',
            shade: [0.5, '#000'],
            shadeClose: 0,
            closeBtn: 1,
            area: ['500px', '430px'],
            content: $('#client-form-dialog')
        });
    };

    $('#client-form-submit').on('click', function() {
        var $dialog = $('#client-form-dialog');
        var id = $.trim($dialog.find('input[name="id"]').val());
        var name = $.trim($dialog.find('input[name="name"]').val());
        var redirectUris = $dialog.find('textarea[name="redirect_uris"]').val();

        if (name === '') {
            layer.msg('请填写应用名称', { icon: 2 });
            return;
        }
        if ($.trim(redirectUris) === '') {
            layer.msg('请填写回调地址白名单', { icon: 2 });
            return;
        }

        var loadIdx = layer.load(2);
        $.ajax({
            url: id !== '' ? '/admin/cmspro/sso/api/clients/' + id : '/admin/cmspro/sso/api/clients',
            type: id !== '' ? 'PUT' : 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ name: name, redirect_uris: redirectUris, remark: $.trim($dialog.find('input[name="remark"]').val()) }),
            success: function(res) {
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.closeAll('page');
                    if (id === '' && res.data && res.data.client_secret) {
                        window.showSecret(res.data.client_id, res.data.client_secret);
                    } else {
                        layer.msg(res.message || '保存成功', { icon: 1, time: 1000 });
                    }
                    table.reload('clients-table');
                } else {
                    layer.msg(res.message || '保存失败', { icon: 2 });
                }
            },
            error: function(xhr) {
                layer.close(loadIdx);
                var msg = '网络错误，请重试';
                try { var r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch(e) {}
                layer.msg(msg, { icon: 2 });
            }
        });
    });

    // 密钥一次性展示弹窗（创建/重置后仅展示一次）
    window.showSecret = function(clientId, secret) {
        layer.open({
            type: 1,
            title: '客户端凭证（仅展示一次，请立即保存）',
            shade: [0.5, '#000'],
            shadeClose: 0,
            closeBtn: 1,
            area: ['520px', '320px'],
            content: '<div style="padding: 20px 24px;">'
                + '<p style="margin-bottom: 8px; color: #ff5722; font-size: 13px;">客户端密钥仅在本次展示，关闭后无法再次查看，请立即复制保存。</p>'
                + '<div style="margin-bottom: 10px;"><span style="color:#666;">客户端 ID：</span>'
                + '<code style="padding: 2px 8px; background: #f6f8fa; border-radius: 3px; user-select: all;">' + clientId + '</code></div>'
                + '<div><span style="color:#666;">客户端密钥：</span>'
                + '<code style="display: block; margin-top: 6px; padding: 8px; background: #f6f8fa; border-radius: 3px; word-break: break-all; user-select: all;">' + secret + '</code></div>'
                + '</div>',
            btn: ['我已保存'],
            yes: function(idx) {
                layer.close(idx);
            }
        });
    };

    // 重置密钥
    window.resetSecret = function(obj) {
        if (!window.hasPermission || !window.hasPermission('cmspro.sso.manage')) {
            layer.msg('无操作权限', { icon: 2, time: 1000 });
            return;
        }
        layer.confirm('重置后旧密钥立即失效，客户端站点需更新配置。确定重置？', {
            icon: 3,
            title: '操作提示',
            btn: ['确定', '取消']
        }, function(index) {
            layer.close(index);
            var loading = layer.load();
            $.ajax({
                url: '/admin/cmspro/sso/api/clients/' + obj.data.id + '/reset-secret',
                type: 'PUT',
                success: function(res) {
                    layer.close(loading);
                    if (res.code === 0 && res.data && res.data.client_secret) {
                        window.showSecret(obj.data.client_id, res.data.client_secret);
                    } else {
                        layer.msg(res.message || '重置失败', { icon: 2, time: 1000 });
                    }
                },
                error: function(xhr) {
                    layer.close(loading);
                    var msg = '请求失败';
                    try { var r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch(e) {}
                    layer.msg(msg, { icon: 2, time: 1000 });
                }
            });
        });
    };

    // 启用/禁用
    window.toggleStatus = function(obj) {
        if (!window.hasPermission || !window.hasPermission('cmspro.sso.manage')) {
            layer.msg('无操作权限', { icon: 2, time: 1000 });
            return;
        }
        var next = obj.data.status === 1 ? 0 : 1;
        var loading = layer.load();
        $.ajax({
            url: '/admin/cmspro/sso/api/clients/' + obj.data.id + '/status',
            type: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify({ status: next }),
            success: function(res) {
                layer.close(loading);
                if (res.code === 0) {
                    layer.msg('状态更新成功', { icon: 1, time: 1000 });
                    table.reload('clients-table');
                } else {
                    layer.msg(res.message || '操作失败', { icon: 2, time: 1000 });
                }
            },
            error: function(xhr) {
                layer.close(loading);
                var msg = '请求失败';
                try { var r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch(e) {}
                layer.msg(msg, { icon: 2, time: 1000 });
            }
        });
    };

    // 删除（同步清理其授权码与访问令牌）
    window.remove = function(obj) {
        if (!window.hasPermission || !window.hasPermission('cmspro.sso.manage')) {
            layer.msg('无操作权限', { icon: 2, time: 1000 });
            return;
        }
        layer.confirm('确定要删除该客户端？其授权码与访问令牌将一并清理，删除后无法恢复！', {
            icon: 3,
            title: '操作提示',
            btn: ['确定', '取消']
        }, function(index) {
            layer.close(index);
            var loading = layer.load();
            $.ajax({
                url: '/admin/cmspro/sso/api/clients/' + obj.data.id,
                type: 'DELETE',
                dataType: 'json',
                success: function(res) {
                    layer.close(loading);
                    if (res.code === 0) {
                        layer.msg('删除成功', { icon: 1, time: 1000 }, function() {
                            obj.del();
                        });
                    } else {
                        layer.msg(res.message || '删除失败', { icon: 2, time: 1000 });
                    }
                },
                error: function(xhr) {
                    layer.close(loading);
                    var msg = '请求失败';
                    try { var r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch(e) {}
                    layer.msg(msg, { icon: 2, time: 1000 });
                }
            });
        });
    };

    // 复制文本
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                layer.msg('已复制', { icon: 1, time: 1000 });
            }).catch(function() {
                layer.msg('复制失败，请手动复制', { icon: 2, time: 1000 });
            });
        } else {
            var input = document.createElement('input');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            layer.msg('已复制', { icon: 1, time: 1000 });
        }
    }

    $(document).on('click', '.copy-text', function() {
        copyText($(this).data('text'));
    });
});
</script>
</body>
</html>
