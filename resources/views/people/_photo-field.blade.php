{{-- Campo de foto compartilhado pelos cadastros. No celular o seletor oferece câmera ou galeria. --}}
<div class="field field-wide photo-field">
    <label for="photo">Foto para identificação</label>
    <div class="photo-field-body">
        @if($person?->exists)<x-person-photo :person="$person" size="medium" />@endif
        <div class="photo-field-input">
            <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
            <small>JPG, PNG ou WEBP com até 5 MB. A foto é recortada em quadrado e fica visível só para quem já acessa o cadastro.</small>
            @if($person?->photo_path)<label class="check-row"><input type="checkbox" name="remove_photo" value="1" @checked(old('remove_photo'))> Remover a foto atual</label>@endif
            @error('photo')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </div>
</div>
