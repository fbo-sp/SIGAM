<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>{{ $title }}</title>
<style>
@page{margin:14mm 12mm 18mm}
body{font-family:'DejaVu Sans',sans-serif;font-size:10px;color:#1d2733;margin:0}
.head{width:100%;border-bottom:2px solid #1f3a68;margin-bottom:8px}.head td{vertical-align:bottom;padding:0 0 5px}
.head h1{font-size:16px;margin:0;color:#1f3a68}.head small{color:#555;font-size:9px}.brand{text-align:right;font-weight:bold;color:#1f3a68}
.meta{width:100%;border-collapse:separate;border-spacing:4px;margin:0 -4px 6px}.meta td{border:1px solid #d5dce6;padding:3px 5px;width:25%;vertical-align:top}.meta b{display:block;font-size:7px;color:#666;text-transform:uppercase}
.list{width:100%;border-collapse:collapse}.list th{background:#1f3a68;color:#fff;text-align:left;padding:4px 5px;font-size:9px}.list td{border-bottom:1px solid #d5dce6;padding:5px}
.list tr.alt td{background:#f6f8fb}.list small{color:#666}.sim{color:#1f7a44;font-weight:bold}.nao{color:#b42318;font-weight:bold}.muted{color:#888}
.box{display:inline-block;width:11px;height:11px;border:1.4px solid #1f3a68}.blank td{height:15px}
.tot{margin-top:8px;font-weight:bold}.tot span{margin-right:18px}
.sign{width:100%;margin-top:30px}.sign td{width:50%;padding:0 18px}.sign div{border-top:1px solid #1d2733;padding-top:3px;text-align:center;color:#555}
.grid{width:100%;border-collapse:collapse}.grid th,.grid td{border:1px solid #c3ccd8;text-align:center;padding:4px 1px}.grid th{background:#1f3a68;color:#fff;font-size:8px}
.grid td.name,.grid th.name{text-align:left;padding-left:4px;white-space:nowrap}.grid td.name small{color:#666;font-size:7px}
</style></head>
<body>
<table class="head"><tr><td><h1>{{ $title }}</h1><small>{{ $subtitle }}</small></td><td class="brand">JUDOMA<br><small>{{ $association->trade_name }}</small></td></tr></table>
@yield('body')
</body></html>
