{{-- Marcações do vínculo. $flags: o vínculo, um array de padrões ou null (volta do formulário com erro, usa old()); $portal: mostra "Liberar o portal". --}}
@php($checked = fn (string $flag) => $flags === null ? (bool) old($flag) : (bool) data_get($flags, $flag))
<div class="guardian-checks">
<label><input type="checkbox" name="is_legal_guardian" value="1" @checked($checked('is_legal_guardian'))> Responsável legal</label>
<label><input type="checkbox" name="is_financial_guardian" value="1" @checked($checked('is_financial_guardian'))> Responsável financeiro</label>
<label><input type="checkbox" name="is_primary" value="1" @checked($checked('is_primary'))> Responsável principal</label>
<label><input type="checkbox" name="can_receive_notifications" value="1" @checked($checked('can_receive_notifications'))> Recebe avisos</label>
@if($portal)<label><input type="checkbox" name="release_portal" value="1" @checked($checked('release_portal'))> Liberar o portal (envia convite para o e-mail)</label>@endif
</div>
