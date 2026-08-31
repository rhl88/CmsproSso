<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>邀请码管理</title>
    <link rel="stylesheet" href="{{ asset('CmsProUi/component/pear/css/pear.css') }}">
    <link rel="stylesheet" href="{{ asset('CmsProUi/font-awesome/4.7.0/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/admin.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/variables.css') }}">
    <link rel="stylesheet" href="{{ asset('Admin/css/reset.css') }}">
    @include('admin.partials.permission-script')
</head>
<body>
<div class="pear-container">
    {{-- 搜索卡 --}}
    <div class="layui-card">
        <div class="layui-card-body">
            <form class="layui-form" action="">
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <input type="text" name="keyword" placeholder="邀请码" class="layui-input">
                    </div>
                </div>
                <div class="layui-inline">
                    <div class="layui-input-inline">
                        <select name="status">
                            <option value="">全部状态</option>
                            <option value="1">未使用</option>
                            <option value="2">已使用</option>
                            <option value="0">禁用</option>
                        </select>
                    </div>
                </div>
                <div class="layui-inline">
                    <button class="layui-btn layui-btn-md" lay-submit lay-filter="invites-query">
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
            <table id="invites-table" lay-filter="invites-table"></table>
        </div>
    </div>

    {{-- 工具栏 --}}
    <script type="text/html" id="invites-toolbar">
        <div class="layui-btn-container">
            <button class="layui-btn layui-btn-sm" lay-event="generate" data-permission="cmspro.sso.manage">
                <i class="layui-icon layui-icon-add-1"></i> 批量生成
            </button>
        </div>
    </script>

    {{-- 状态模板 --}}
    @verbatim
    <script type="text/html" id="statusTpl">
        {{# if(d.status === 1){ }}
        <span class="layui-badge layui-bg-green">未使用</span>
        {{# } else if(d.status === 2){ }}
        <span class="layui-badge layui-bg-blue">已使用</span>
        {{# } else { }}
        <span class="layui-badge layui-bg-gray">禁用</span>
        {{# } }}
    </script>

    <script type="text/html" id="priceTpl">
        {{# if(d.price !== null && d.price !== ''){ }}
        ¥{{ d.price }}
        {{# } else { }}
        <span style="color: #999;">-</span>
        {{# } }}
    </script>

    <script type="text/html" id="usedAtTpl">
        {{# if(d.used_at){ }}
        {{ d.used_at }}
        {{# } else { }}
        <span style="color: #999;">-</span>
        {{# } }}
    </script>

    <script type="text/html" id="invites-bar">
        <div class="layui-btn-container">
            <a class="layui-btn layui-btn-xs" lay-event="more">操作 <i class="layui-icon layui-icon-down"></i></a>
        </div>
    </script>
    @endverbatim

    {{-- 批量生成弹窗 --}}
    <div id="generate-dialog" style="display:none; padding: 20px 20px 0;">
        <form class="layui-form" lay-filter="generate-form">
            <div class="layui-form-item">
                <label class="layui-form-label">生成数量</label>
                <div class="layui-input-block">
                    <input type="text" name="count" value="10" placeholder="1-500" class="layui-input" style="width: 220px;">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">邀请码费用</label>
                <div class="layui-input-block">
                    <input type="text" name="price" placeholder="留空表示免费，如 9.90" class="layui-input" style="width: 220px;">
                </div>
            </div>
            <div class="layui-form-item" style="text-align: center;">
                <button type="button" class="layui-btn" id="generate-submit">
                    <i class="layui-icon layui-icon-ok"></i> 生成
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
        elem: '#invites-table',
        url: '/admin/cmspro/sso/api/invites',
        toolbar: '#invites-toolbar',
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
        maxHeight: ($(window).height() - 200) + 'px',
        cols: [[
            { title: 'ID', field: 'id', align: 'center', fixed: 'left', unresize: true },
            { title: '邀请码', field: 'code', align: 'center', templet: function(d) { return '<a href="javascript:;" class="copy-code" style="color:#1e9fff;cursor:pointer;" data-code="' + d.code + '" title="点击复制">' + d.code + '</a>'; } },
            { title: '状态', field: 'status', align: 'center', templet: '#statusTpl' },
            { title: '费用', field: 'price', align: 'center', templet: '#priceTpl' },
            { title: '使用者ID', field: 'used_by', align: 'center', templet: function(d) { return d.used_by || '<span style="color:#999;">-</span>'; } },
            { title: '使用时间', field: 'used_at', align: 'center', templet: '#usedAtTpl' },
            { title: '创建时间', field: 'create_time', align: 'center', sort: true },
            { title: '操作', toolbar: '#invites-bar', align: 'center', fixed: 'right' }
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
    form.on('submit(invites-query)', function(data) {
        inst.reload({ where: data.field, page: { curr: 1 } });
        return false;
    });

    // 工具栏事件
    table.on('toolbar(invites-table)', function(obj) {
        if (obj.event === 'generate') {
            window.generate();
        } else if (obj.event === 'refresh') {
            table.reload('invites-table');
        }
    });

    // 行工具事件
    table.on('tool(invites-table)', function(obj) {
        if (obj.event === 'more') {
            var isUsed = obj.data.status === 2;
            layui.dropdown.render({
                elem: this,
                show: true,
                align: 'right',
                data: [
                    { title: '复制邀请码', id: 'copy' },
                    { title: '启用/禁用', id: 'toggle' },
                    { title: '删除', id: 'del' }
                ].filter(function(item) {
                    // 已使用的邀请码仅保留复制，用于追溯
                    return !isUsed || item.id === 'copy';
                }),
                click: function(menudata) {
                    if (menudata.id === 'copy') {
                        copyCode(obj.data.code);
                    } else if (menudata.id === 'toggle') {
                        window.toggleStatus(obj);
                    } else if (menudata.id === 'del') {
                        window.remove(obj);
                    }
                }
            });
        }
    });

    // 批量生成
    window.generate = function() {
        if (!window.hasPermission || !window.hasPermission('cmspro.sso.manage')) {
            layer.msg('无操作权限', { icon: 2, time: 1000 });
            return;
        }
        layer.open({
            type: 1,
            title: '批量生成邀请码',
            shade: [0.5, '#000'],
            shadeClose: 0,
            closeBtn: 1,
            area: ['420px', '300px'],
            content: $('#generate-dialog')
        });
    };

    $('#generate-submit').on('click', function() {
        var count = parseInt($('#generate-dialog input[name="count"]').val(), 10);
        var price = $.trim($('#generate-dialog input[name="price"]').val());

        if (!count || count < 1 || count > 500) {
            layer.msg('生成数量须为 1-500', { icon: 2 });
            return;
        }
        if (price !== '' && !/^\d+(\.\d{1,2})?$/.test(price)) {
            layer.msg('邀请码费用须为不小于 0 的数字（最多两位小数）', { icon: 2 });
            return;
        }

        var loadIdx = layer.load(2);
        $.ajax({
            url: '/admin/cmspro/sso/api/invites/generate',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ count: count, price: price }),
            success: function(res) {
                layer.close(loadIdx);
                if (res.code === 0) {
                    layer.msg(res.message || '生成成功', { icon: 1, time: 1500 }, function() {
                        layer.closeAll('page');
                        table.reload('invites-table');
                    });
                } else {
                    layer.msg(res.message || '生成失败', { icon: 2 });
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

    // 启用/禁用（仅未使用的邀请码）
    window.toggleStatus = function(obj) {
        if (!window.hasPermission || !window.hasPermission('cmspro.sso.manage')) {
            layer.msg('无操作权限', { icon: 2, time: 1000 });
            return;
        }
        var next = obj.data.status === 0 ? 1 : 0;
        var loading = layer.load();
        $.ajax({
            url: '/admin/cmspro/sso/api/invites/' + obj.data.id + '/status',
            type: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify({ status: next }),
            success: function(res) {
                layer.close(loading);
                if (res.code === 0) {
                    layer.msg('状态更新成功', { icon: 1, time: 1000 });
                    table.reload('invites-table');
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

    // 删除（仅未使用/禁用的邀请码）
    window.remove = function(obj) {
        if (!window.hasPermission || !window.hasPermission('cmspro.sso.manage')) {
            layer.msg('无操作权限', { icon: 2, time: 1000 });
            return;
        }
        layer.confirm('确定要删除该邀请码？删除后无法恢复！', {
            icon: 3,
            title: '操作提示',
            btn: ['确定', '取消']
        }, function(index) {
            layer.close(index);
            var loading = layer.load();
            $.ajax({
                url: '/admin/cmspro/sso/api/invites/' + obj.data.id,
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

    // 复制邀请码
    function copyCode(code) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(function() {
                layer.msg('已复制邀请码', { icon: 1, time: 1000 });
            }).catch(function() {
                layer.msg('复制失败，请手动复制', { icon: 2, time: 1000 });
            });
        } else {
            var input = document.createElement('input');
            input.value = code;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            layer.msg('已复制邀请码', { icon: 1, time: 1000 });
        }
    }

    $(document).on('click', '.copy-code', function() {
        copyCode($(this).data('code'));
    });
});
</script>
</body>
</html>
