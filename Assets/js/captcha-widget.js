/**
 * CmsPro 验证码通用组件（前台登录/注册等表单接入用）
 *
 * 依赖「验证码」应用（cmspro.captcha）的公开 API：
 *   POST {baseUrl}/generate  生成验证码，返回 { key, type, expires_in, image, ... }
 *   （校验不在此组件内进行——key + answer 随表单提交，由业务接口调用 CaptchaService::verify 一次性校验）
 *
 * 用法：
 *   var captcha = new CaptchaWidget({
 *       el: '#captcha-box',         // 容器选择器或 DOM 元素
 *       driver: 'image',            // 验证码模式（image/math/chinese/click/slider/rotate/click_button）
 *       baseUrl: '/api/captcha',    // 验证码 API 基础路径
 *       csrfToken: '...'            // CSRF Token（缺省读取 meta[name="csrf-token"]）
 *   });
 *
 * 表单提交时取值：
 *   data.captcha_key    = captcha.getKey();
 *   data.captcha_answer = captcha.getAnswer();
 *   if (!captcha.isAnswerReady()) { /* 提示先完成验证 *\/ }
 *
 * 服务端校验失败后刷新验证码：
 *   captcha.refresh();
 */
(function () {
    'use strict';

    var STYLE_ID = 'captcha-widget-style';

    var CSS = ''
        + '.cw-box{width:100%;font-size:13px;color:#475569;}'
        + '.cw-error{padding:6px 10px;border-left:3px solid #ef4444;background:#fef2f2;color:#991b1b;border-radius:4px;line-height:1.6;}'
        + '.cw-retry{color:#2563eb;cursor:pointer;text-decoration:none;}'
        + '.cw-hint{font-size:12px;color:#94a3b8;margin-top:6px;}'
        + '.cw-expired{padding:8px 12px;text-align:center;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:6px;color:#94a3b8;cursor:pointer;}'
        + '.cw-input-row{display:flex;align-items:center;gap:8px;}'
        + '.cw-input-row img{border-radius:4px;border:1px solid #e2e8f0;flex:0 0 auto;cursor:pointer;}'
        + '.cw-input-row input{flex:1;min-width:0;height:40px;border:1px solid #d2d2d2;border-radius:4px;padding:0 10px;font-size:14px;outline:none;}'
        + '.cw-input-row input:focus{border-color:#2563eb;}'
        + '.cw-click-wrap{text-align:center;}'
        + '.cw-click-target{font-size:14px;color:#2563eb;font-weight:bold;margin-bottom:8px;}'
        + '.cw-click-area{position:relative;display:inline-block;cursor:crosshair;max-width:100%;}'
        + '.cw-click-area img{border-radius:6px;border:1px solid #e2e8f0;display:block;max-width:100%;}'
        + '.cw-click-mark{position:absolute;width:22px;height:22px;border-radius:50%;background:rgba(37,99,235,0.3);'
        + 'border:2px solid #2563eb;transform:translate(-50%,-50%);font-size:11px;color:#2563eb;line-height:18px;'
        + 'text-align:center;pointer-events:none;}'
        + '.cw-click-foot{margin-top:6px;font-size:12px;color:#64748b;}'
        + '.cw-click-foot a{color:#2563eb;cursor:pointer;}'
        + '.cw-slider-wrap{text-align:left;}'
        + '.cw-slider-img{position:relative;display:inline-block;max-width:100%;}'
        + '.cw-slider-img img.bg{border-radius:6px;border:1px solid #e2e8f0;display:block;max-width:100%;}'
        + '.cw-slider-img img.piece{position:absolute;border-radius:4px;}'
        + '.cw-slider-track{position:relative;height:40px;border-radius:20px;background:#e2e8f0;cursor:ew-resize;user-select:none;touch-action:none;margin-top:10px;}'
        + '.cw-slider-hint{position:absolute;top:0;left:0;right:0;text-align:center;color:#94a3b8;font-size:12px;line-height:40px;pointer-events:none;z-index:1;}'
        + '.cw-slider-thumb{position:absolute;top:2px;left:0;width:44px;height:36px;border-radius:18px;z-index:2;cursor:grab;'
        + 'background:linear-gradient(135deg,#2563eb,#3b82f6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;}'
        + '.cw-slider-thumb:active{cursor:grabbing;}'
        + '.cw-rotate-wrap{text-align:center;}'
        + '.cw-rotate-row{display:flex;align-items:center;justify-content:center;gap:10px;}'
        + '.cw-rotate-btn{flex:0 0 auto;width:36px;height:36px;border:1px solid #e2e8f0;border-radius:50%;background:#f8fafc;'
        + 'color:#2563eb;font-size:18px;line-height:1;cursor:pointer;user-select:none;-webkit-user-select:none;touch-action:none;}'
        + '.cw-rotate-btn:active{background:#dbeafe;}'
        + '.cw-rotate-img{width:160px;height:160px;border-radius:8px;border:1px solid #e2e8f0;cursor:grab;user-select:none;touch-action:none;}'
        + '.cw-rotate-hint{margin-top:10px;color:#475569;font-size:12px;}'
        + '.cw-btn-wrap{text-align:center;padding:14px 0 6px;}'
        + '.cw-btn{display:inline-flex;align-items:center;justify-content:center;width:100%;max-width:280px;height:44px;'
        + 'border:none;border-radius:22px;background:linear-gradient(135deg,#2563eb,#3b82f6);color:#fff;font-size:14px;cursor:pointer;}'
        + '.cw-btn.done{background:#16a34a;cursor:default;}';

    function injectStyle() {
        if (document.getElementById(STYLE_ID)) {
            return;
        }
        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    function CaptchaWidget(options) {
        var opts = options || {};
        this.opts = {
            el: opts.el,
            driver: opts.driver || 'image',
            baseUrl: (opts.baseUrl || '/api/captcha').replace(/\/+$/, ''),
            csrfToken: opts.csrfToken
                || (document.querySelector('meta[name="csrf-token"]') || {}).content
                || ''
        };

        this.container = typeof this.opts.el === 'string'
            ? document.querySelector(this.opts.el)
            : this.opts.el;
        if (!this.container) {
            throw new Error('CaptchaWidget: 验证码容器不存在: ' + this.opts.el);
        }

        this.key = '';
        this.answer = null;
        this.type = this.opts.driver;
        this.expiresAt = 0;
        this.timer = null;
        this.loading = false;

        injectStyle();
        this.refresh();
    }

    CaptchaWidget.prototype = {

        /** 当前验证码 key（随表单提交 captcha_key） */
        getKey: function () {
            return this.key;
        },

        /** 当前答案（随表单提交 captcha_answer；格式随模式不同） */
        getAnswer: function () {
            if (this.type === 'click') {
                return JSON.stringify(this.answer || []);
            }
            if (this.answer === null || this.answer === undefined) {
                return '';
            }
            return String(this.answer);
        },

        /** 用户是否已完成人机交互（提交前检查，避免空答案直接提交） */
        isAnswerReady: function () {
            if (!this.key || this.loading) {
                return false;
            }
            if (this.expiresAt && Date.now() > this.expiresAt * 1000) {
                return false;
            }
            switch (this.type) {
                case 'image':
                case 'math':
                case 'chinese':
                case 'click_button':
                    return String(this.answer || '').trim() !== '';
                case 'click':
                    return (this.answer || []).length > 0;
                case 'slider':
                case 'rotate':
                    return Number(this.answer) > 0;
                default:
                    return false;
            }
        },

        /** 重新生成验证码（服务端校验失败、过期后调用） */
        refresh: function () {
            var self = this;
            if (this.loading) {
                return;
            }
            this.loading = true;
            this.key = '';
            this.answer = null;
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
            this.renderMessage('正在生成验证码...');

            this.post('generate', { driver: this.opts.driver }, function (err, data) {
                self.loading = false;
                if (err) {
                    self.renderError(err);
                    return;
                }
                self.type = data.type || self.opts.driver;
                self.key = data.key || '';
                self.expiresAt = data.expires_in ? Math.floor(Date.now() / 1000) + Number(data.expires_in) : 0;
                self.resetAnswer();
                self.render(data);
                self.startCountdown(data.expires_in || 300);
            });
        },

        // ------------------------------------------------------------ 内部渲染

        resetAnswer: function () {
            switch (this.type) {
                case 'click':
                    this.answer = [];
                    break;
                case 'slider':
                case 'rotate':
                    this.answer = 0;
                    break;
                default:
                    this.answer = '';
            }
        },

        renderMessage: function (text) {
            this.container.innerHTML = '<div class="cw-box"><div class="cw-hint">' + this.esc(text) + '</div></div>';
        },

        renderError: function (message) {
            var self = this;
            this.container.innerHTML = '<div class="cw-box"><div class="cw-error">'
                + this.esc(message || '验证码加载失败')
                + ' <a class="cw-retry">点击重试</a></div></div>';
            this.container.querySelector('.cw-retry').addEventListener('click', function () {
                self.refresh();
            });
        },

        render: function (data) {
            switch (this.type) {
                case 'image':
                case 'math':
                case 'chinese':
                    this.renderInputCaptcha(data);
                    break;
                case 'click':
                    this.renderClickCaptcha(data);
                    break;
                case 'slider':
                    this.renderSliderCaptcha(data);
                    break;
                case 'rotate':
                    this.renderRotateCaptcha(data);
                    break;
                case 'click_button':
                    this.renderClickButton(data);
                    break;
                default:
                    this.renderError('不支持的验证码模式: ' + this.type);
            }
        },

        // 图片字符 / 算术 / 中文：图片 + 输入框
        renderInputCaptcha: function (data) {
            var self = this;
            var placeholder = this.type === 'math' ? '输入计算结果' : '请输入图中字符';
            // 算术验证码图片内已绘制算式，无需重复显示文字
            var question = (data.question && this.type !== 'math') ? '<div style="margin-bottom:8px;">' + this.esc(data.question) + '</div>' : '';

            this.container.innerHTML = '<div class="cw-box">'
                + question
                + '<div class="cw-input-row">'
                + '<img src="' + data.image + '" alt="验证码" title="点击刷新" width="' + (data.width || 120) + '" height="' + (data.height || 40) + '">'
                + '<input type="text" maxlength="10" placeholder="' + placeholder + '" autocomplete="off">'
                + '</div>'
                + '<div class="cw-hint">看不清？点击图片可刷新</div>'
                + '</div>';

            var input = this.container.querySelector('input');
            input.addEventListener('input', function () {
                self.answer = input.value;
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });
            // 点击图片刷新
            this.container.querySelector('img').addEventListener('click', function () {
                self.refresh();
            });
            this.inputEl = input;
        },

        // 文字点选
        renderClickCaptcha: function (data) {
            var self = this;
            this.container.innerHTML = '<div class="cw-box cw-click-wrap">'
                + '<div class="cw-click-target">' + this.esc(data.target_text || '') + '</div>'
                + '<div class="cw-click-area"><img src="' + data.image + '" alt="点选验证码"></div>'
                + '<div class="cw-click-foot">已点击 <span class="cw-count">0</span> 次 &nbsp;|&nbsp; <a class="cw-reset">重置</a> &nbsp;|&nbsp; <a class="cw-refresh">刷新</a></div>'
                + '</div>';

            var area = this.container.querySelector('.cw-click-area');
            var countEl = this.container.querySelector('.cw-count');

            area.addEventListener('click', function (e) {
                var rect = area.querySelector('img').getBoundingClientRect();
                var x = Math.round(e.clientX - rect.left);
                var y = Math.round(e.clientY - rect.top);
                self.answer.push({ x: x, y: y });

                var mark = document.createElement('div');
                mark.className = 'cw-click-mark';
                mark.textContent = self.answer.length;
                mark.style.left = x + 'px';
                mark.style.top = y + 'px';
                area.appendChild(mark);
                countEl.textContent = self.answer.length;
            });

            this.container.querySelector('.cw-reset').addEventListener('click', function () {
                self.answer = [];
                countEl.textContent = '0';
                var marks = self.container.querySelectorAll('.cw-click-mark');
                for (var i = 0; i < marks.length; i++) {
                    marks[i].remove();
                }
            });

            // 刷新：换一张全新的点选验证码
            this.container.querySelector('.cw-refresh').addEventListener('click', function () {
                self.refresh();
            });
        },

        // 滑块拼图
        renderSliderCaptcha: function (data) {
            var self = this;
            var sliderY = data.slider_y || 60;
            var w = data.width || 280;

            this.container.innerHTML = '<div class="cw-box cw-slider-wrap">'
                + '<div class="cw-slider-img">'
                + '<img class="bg" src="' + data.bg_image + '" alt="滑块拼图" width="' + w + '">'
                + '<img class="piece" src="' + data.slider_image + '" style="top:' + sliderY + 'px;left:0;">'
                + '</div>'
                + '<div class="cw-slider-track" style="width:' + w + 'px;">'
                + '<div class="cw-slider-hint">按住滑块拖动完成拼图</div>'
                + '<div class="cw-slider-thumb">&#8594;</div>'
                + '</div>'
                + '</div>';

            var track = this.container.querySelector('.cw-slider-track');
            var thumb = this.container.querySelector('.cw-slider-thumb');
            var piece = this.container.querySelector('.cw-slider-img .piece');
            var hint = this.container.querySelector('.cw-slider-hint');
            var maxLeft = w - 44;

            this.bindDrag(track, {
                getBase: function () {
                    return parseFloat(thumb.style.left) || 0;
                },
                onStart: function () {
                    hint.textContent = '按住滑块拖动完成拼图';
                },
                onMove: function (delta, baseLeft) {
                    var left = Math.max(0, Math.min(maxLeft, baseLeft + delta));
                    thumb.style.left = left + 'px';
                    piece.style.left = left + 'px';
                    self.answer = left;
                    return left;
                },
                onEnd: function () {
                    hint.textContent = '松开滑块即可提交验证';
                }
            });
        },

        // 旋转验证
        renderRotateCaptcha: function (data) {
            var self = this;
            this.container.innerHTML = '<div class="cw-box cw-rotate-wrap">'
                + '<div style="margin-bottom:8px;color:#64748b;">' + this.esc(data.hint || '旋转图片使十字箭头朝上') + '</div>'
                + '<div class="cw-rotate-row">'
                + '<button type="button" class="cw-rotate-btn" data-step="-5" title="向左旋转">&#8634;</button>'
                + '<img class="cw-rotate-img" src="' + data.image + '" alt="旋转验证" style="width:' + (data.size || 160) + 'px;height:' + (data.size || 160) + 'px;">'
                + '<button type="button" class="cw-rotate-btn" data-step="5" title="向右旋转">&#8635;</button>'
                + '</div>'
                + '<div class="cw-rotate-hint">当前角度：<span class="cw-value">0</span>&deg;</div>'
                + '</div>';

            var img = this.container.querySelector('.cw-rotate-img');
            var valueEl = this.container.querySelector('.cw-value');
            var rotation = 0;

            // 设置绝对旋转角度并同步答案（CSS rotate 正值=顺时针）
            function setRotation(angle) {
                rotation = angle;
                img.style.transform = 'rotate(' + rotation + 'deg)';
                valueEl.textContent = Math.round(((rotation % 360) + 360) % 360);
                self.answer = Math.round(((rotation % 360) + 360) % 360);
            }

            // 左右按钮：单击微调 ±5°，按住 400ms 后连续旋转（缓慢调整）
            this.container.querySelectorAll('.cw-rotate-btn').forEach(function (btn) {
                var step = Number(btn.getAttribute('data-step')) || 0;
                var delayTimer = null;
                var repeatTimer = null;

                function stopRepeat() {
                    if (delayTimer) {
                        clearTimeout(delayTimer);
                        delayTimer = null;
                    }
                    if (repeatTimer) {
                        clearInterval(repeatTimer);
                        repeatTimer = null;
                    }
                }

                function startHold(e) {
                    e.preventDefault();
                    setRotation(rotation + step);
                    stopRepeat();
                    delayTimer = setTimeout(function () {
                        repeatTimer = setInterval(function () {
                            setRotation(rotation + step);
                        }, 120);
                    }, 400);
                }

                btn.addEventListener('mousedown', startHold);
                btn.addEventListener('touchstart', startHold);
                ['mouseup', 'mouseleave', 'touchend', 'touchcancel'].forEach(function (ev) {
                    btn.addEventListener(ev, stopRepeat);
                });
            });

            // 图片上下拖动同样可旋转（基准角度 + 总位移）
            this.bindDrag(img, {
                vertical: true,
                getBase: function () {
                    return rotation;
                },
                onStart: function () {},
                onMove: function (delta, base) {
                    setRotation(base + delta * 1.5);
                },
                onEnd: function () {}
            });
        },

        // 点击按钮
        renderClickButton: function (data) {
            var self = this;
            this.container.innerHTML = '<div class="cw-box">'
                + '<div style="text-align:center;color:#64748b;margin-bottom:10px;">' + this.esc(data.message || '点击下方按钮完成验证') + '</div>'
                + '<div class="cw-btn-wrap"><button type="button" class="cw-btn">点击完成验证</button></div>'
                + '</div>';

            var btn = this.container.querySelector('.cw-btn');
            btn.addEventListener('click', function () {
                if (btn.classList.contains('done')) {
                    return;
                }
                btn.classList.add('done');
                btn.textContent = '验证通过';
                self.answer = 'clicked';
            });
        },

        // ------------------------------------------------------------ 工具方法

        /**
         * 绑定拖动（鼠标 + 触摸）
         * handlers: { vertical: bool, getBase, onStart, onMove(totalDelta, basePos), onEnd }
         * onMove 收到自拖动开始起的总位移与按下时捕获的基准值（getBase）
         */
        bindDrag: function (el, handlers) {
            var dragging = false;
            var startPos = 0;
            var basePos = 0;

            function posFromEvent(e) {
                return handlers.vertical
                    ? (e.touches ? e.touches[0].clientY : e.clientY)
                    : (e.touches ? e.touches[0].clientX : e.clientX);
            }

            function move(e) {
                if (!dragging) {
                    return;
                }
                // 总位移 + 拖动起始基准（getBase 在按下时捕获，拖动过程中基准不变）
                handlers.onMove(posFromEvent(e) - startPos, basePos);
                if (e.cancelable) {
                    e.preventDefault();
                }
            }

            function end() {
                if (!dragging) {
                    return;
                }
                dragging = false;
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', end);
                document.removeEventListener('touchmove', move);
                document.removeEventListener('touchend', end);
                handlers.onEnd();
            }

            function begin(e) {
                if (dragging) {
                    return;
                }
                dragging = true;
                startPos = posFromEvent(e);
                basePos = handlers.getBase ? handlers.getBase() : 0;
                handlers.onStart();
                document.addEventListener('mousemove', move);
                document.addEventListener('mouseup', end);
                document.addEventListener('touchmove', move, { passive: false });
                document.addEventListener('touchend', end);
            }

            el.addEventListener('mousedown', begin);
            el.addEventListener('touchstart', begin, { passive: true });
        },

        /** 倒计时：过期后提示刷新 */
        startCountdown: function (seconds) {
            var self = this;
            var remaining = Number(seconds) || 300;

            if (this.timer) {
                clearInterval(this.timer);
            }
            this.timer = setInterval(function () {
                remaining--;
                if (remaining > 0) {
                    return;
                }
                clearInterval(self.timer);
                self.timer = null;
                // 过期：整体替换为刷新提示，保留容器其余部分交互不再有意义
                var expired = document.createElement('div');
                expired.className = 'cw-box';
                expired.innerHTML = '<div class="cw-expired">验证码已过期，点击刷新</div>';
                expired.addEventListener('click', function () {
                    self.refresh();
                });
                self.container.innerHTML = '';
                self.container.appendChild(expired);
            }, 1000);
        },

        post: function (action, data, callback) {
            var self = this;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', this.opts.baseUrl + '/' + action, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', this.opts.csrfToken);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) {
                    return;
                }
                var res = null;
                try {
                    res = JSON.parse(xhr.responseText);
                } catch (e) {
                    callback('验证码服务响应异常');
                    return;
                }
                if (xhr.status !== 200 || !res || res.code !== 0 || !res.data) {
                    callback((res && res.message) || '验证码生成失败，请稍后重试');
                    return;
                }
                callback(null, res.data);
            };
            xhr.send(JSON.stringify(data));
        },

        esc: function (text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(String(text)));
            return div.innerHTML;
        }
    };

    window.CaptchaWidget = CaptchaWidget;
})();
