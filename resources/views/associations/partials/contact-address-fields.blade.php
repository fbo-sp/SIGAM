<fieldset><legend>Contato</legend><div class="form-grid">
    <div class="field"><label for="email">E-mail</label><input type="email" id="email" name="email" value="{{ old('email', $association->email) }}">@error('email')<p class="field-error">{{ $message }}</p>@enderror</div>
    <div class="field"><label for="phone">Telefone</label><input id="phone" name="phone" inputmode="tel" value="{{ old('phone', $association->phone) }}">@error('phone')<p class="field-error">{{ $message }}</p>@enderror</div>
    <div class="field field-wide"><label for="website">Site</label><input type="url" id="website" name="website" value="{{ old('website', $association->website) }}" placeholder="https://">@error('website')<p class="field-error">{{ $message }}</p>@enderror</div>
</div></fieldset>
<fieldset><legend>Endereço</legend><div class="form-grid">
    <div class="field"><label for="postal_code">CEP</label><input id="postal_code" name="postal_code" inputmode="numeric" value="{{ old('postal_code', $association->postal_code) }}">@error('postal_code')<p class="field-error">{{ $message }}</p>@enderror</div>
    <div class="field"><label for="street">Logradouro</label><input id="street" name="street" value="{{ old('street', $association->street) }}"></div>
    <div class="field"><label for="number">Número</label><input id="number" name="number" value="{{ old('number', $association->number) }}"></div>
    <div class="field"><label for="complement">Complemento</label><input id="complement" name="complement" value="{{ old('complement', $association->complement) }}"></div>
    <div class="field"><label for="district">Bairro</label><input id="district" name="district" value="{{ old('district', $association->district) }}"></div>
    <div class="field"><label for="city">Município</label><input id="city" name="city" value="{{ old('city', $association->city) }}"></div>
    <div class="field"><label for="state">UF</label><input id="state" name="state" maxlength="2" value="{{ old('state', $association->state) }}"></div>
    <input type="hidden" name="country" value="{{ old('country', $association->country ?: 'BR') }}">
</div></fieldset>
