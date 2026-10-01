{{-- Berufstitel im Formular einer Person (Einstellungen → Benutzer), nach BookStacks users.parts.form.
     Gespeichert mit demselben „Speichern“: functions.php hört auf user_create/user_update und ruft
     Anmeldung::ausFormular. Nur für Admins (Recht „Benutzer verwalten“); die Vorschau macht wiki.js (titelVorschau). --}}
@if(userCan('users-manage'))
    @php
        $gespeichert = \FundusAnmeldung\Anmeldung::gespeichert(isset($model) ? $model->id : null);
        $stufen = [
            '' => 'Automatisch aus dem Titel',
            'leitung' => 'Leitung (schwarz)',
            'senior' => 'Senior (blau)',
            'junior' => 'Junior (türkis)',
            'azubi' => 'Azubi (grün)',
            'team' => 'Team (grau)',
        ];
        $titel = old('fundus_titel', $gespeichert->titel ?? '');
        $stufe = old('fundus_stufe', $gespeichert->stufe ?? '');
    @endphp
    <div class="grid half gap-xl fundus-titel-feld" data-fundus-titel-feld>
        <div>
            <label for="fundus-titel" class="setting-list-label">Berufstitel</label>
            <p class="small">Steht neben dem Namen im ganzen Wiki. Die Stufe bestimmt die Farbe. Leer lassen: kein Titel.</p>
        </div>
        <div>
            <input type="text" id="fundus-titel" name="fundus_titel" maxlength="120" value="{{ $titel }}" placeholder="z. B. Junior Consultant" autocomplete="off">
            <select id="fundus-stufe" name="fundus_stufe" aria-label="Stufe">
                @foreach($stufen as $wert => $name)
                    <option value="{{ $wert }}" @selected($stufe === $wert)>{{ $name }}</option>
                @endforeach
            </select>
            <p class="fundus-titel-vorschau">Vorschau: <span data-fundus-titel-vorschau class="fundus-titel stufe-team" hidden></span></p>
        </div>
    </div>
@endif
