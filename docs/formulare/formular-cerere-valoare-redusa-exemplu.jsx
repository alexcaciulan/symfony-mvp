import { useState } from "react";

const STEPS = [
  { id: 1, label: "Instanța", icon: "🏛️" },
  { id: 2, label: "Reclamant", icon: "👤" },
  { id: 3, label: "Pârât", icon: "👥" },
  { id: 4, label: "Creanța", icon: "💰" },
  { id: 5, label: "Probe", icon: "📎" },
  { id: 6, label: "Confirmare", icon: "✅" },
];

const counties = [
  "Alba", "Arad", "Argeș", "Bacău", "Bihor", "Bistrița-Năsăud", "Botoșani",
  "Brașov", "Brăila", "București", "Buzău", "Caraș-Severin", "Călărași",
  "Cluj", "Constanța", "Covasna", "Dâmbovița", "Dolj", "Galați", "Giurgiu",
  "Gorj", "Harghita", "Hunedoara", "Ialomița", "Iași", "Ilfov", "Maramureș",
  "Mehedinți", "Mureș", "Neamț", "Olt", "Prahova", "Satu Mare", "Sălaj",
  "Sibiu", "Suceava", "Teleorman", "Timiș", "Tulcea", "Vaslui", "Vâlcea", "Vrancea",
];

function Field({ label, required, hint, children, wide }) {
  return (
    <div style={{ marginBottom: 20, gridColumn: wide ? "1 / -1" : undefined }}>
      <label style={{
        display: "block",
        fontSize: 13,
        fontWeight: 600,
        color: "#1a2a3a",
        marginBottom: 6,
        fontFamily: "'DM Sans', sans-serif",
        letterSpacing: "0.01em",
      }}>
        {label}
        {required && <span style={{ color: "#c0392b", marginLeft: 3 }}>*</span>}
      </label>
      {children}
      {hint && (
        <p style={{
          fontSize: 12,
          color: "#7f8c9b",
          marginTop: 5,
          marginBottom: 0,
          fontFamily: "'DM Sans', sans-serif",
          lineHeight: 1.4,
        }}>
          {hint}
        </p>
      )}
    </div>
  );
}

const inputStyle = {
  width: "100%",
  padding: "10px 14px",
  border: "1.5px solid #d5dce6",
  borderRadius: 10,
  fontSize: 14,
  fontFamily: "'DM Sans', sans-serif",
  color: "#1a2a3a",
  background: "#fafbfc",
  outline: "none",
  transition: "border-color 0.2s, box-shadow 0.2s",
  boxSizing: "border-box",
};

const inputFocusHandler = (e) => {
  e.target.style.borderColor = "#2563eb";
  e.target.style.boxShadow = "0 0 0 3px rgba(37,99,235,0.1)";
};
const inputBlurHandler = (e) => {
  e.target.style.borderColor = "#d5dce6";
  e.target.style.boxShadow = "none";
};

function Input(props) {
  return (
    <input
      {...props}
      style={{ ...inputStyle, ...props.style }}
      onFocus={inputFocusHandler}
      onBlur={inputBlurHandler}
    />
  );
}

function Select({ children, ...props }) {
  return (
    <select
      {...props}
      style={{ ...inputStyle, ...props.style, cursor: "pointer", appearance: "auto" }}
      onFocus={inputFocusHandler}
      onBlur={inputBlurHandler}
    >
      {children}
    </select>
  );
}

function TextArea(props) {
  return (
    <textarea
      {...props}
      style={{
        ...inputStyle,
        minHeight: 100,
        resize: "vertical",
        lineHeight: 1.5,
        ...props.style,
      }}
      onFocus={inputFocusHandler}
      onBlur={inputBlurHandler}
    />
  );
}

function InfoBox({ children, type = "info" }) {
  const colors = {
    info: { bg: "#eff6ff", border: "#bfdbfe", icon: "ℹ️", text: "#1e40af" },
    warning: { bg: "#fffbeb", border: "#fde68a", icon: "⚠️", text: "#92400e" },
    success: { bg: "#f0fdf4", border: "#bbf7d0", icon: "✅", text: "#166534" },
    legal: { bg: "#faf5ff", border: "#e9d5ff", icon: "⚖️", text: "#6b21a8" },
  };
  const c = colors[type];
  return (
    <div style={{
      background: c.bg,
      border: `1px solid ${c.border}`,
      borderRadius: 12,
      padding: "14px 16px",
      marginBottom: 20,
      display: "flex",
      gap: 10,
      alignItems: "flex-start",
    }}>
      <span style={{ fontSize: 16, flexShrink: 0, marginTop: 1 }}>{c.icon}</span>
      <p style={{
        margin: 0,
        fontSize: 13,
        color: c.text,
        lineHeight: 1.5,
        fontFamily: "'DM Sans', sans-serif",
      }}>
        {children}
      </p>
    </div>
  );
}

function FileUploadZone({ files, onAdd, onRemove }) {
  return (
    <div>
      <div
        onClick={() => {}}
        style={{
          border: "2px dashed #d5dce6",
          borderRadius: 12,
          padding: "30px 20px",
          textAlign: "center",
          cursor: "pointer",
          background: "#fafbfc",
          transition: "all 0.2s",
          marginBottom: files.length > 0 ? 12 : 0,
        }}
        onMouseEnter={(e) => {
          e.currentTarget.style.borderColor = "#2563eb";
          e.currentTarget.style.background = "#eff6ff";
        }}
        onMouseLeave={(e) => {
          e.currentTarget.style.borderColor = "#d5dce6";
          e.currentTarget.style.background = "#fafbfc";
        }}
      >
        <div style={{ fontSize: 28, marginBottom: 8 }}>📄</div>
        <p style={{ margin: 0, fontSize: 14, color: "#4b5563", fontFamily: "'DM Sans', sans-serif", fontWeight: 600 }}>
          Trage fișierele aici sau click pentru a încărca
        </p>
        <p style={{ margin: "6px 0 0", fontSize: 12, color: "#9ca3af", fontFamily: "'DM Sans', sans-serif" }}>
          PDF, JPG, PNG, DOCX — max. 10 MB per fișier
        </p>
      </div>
      {files.map((f, i) => (
        <div key={i} style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          padding: "10px 14px",
          background: "#f0fdf4",
          border: "1px solid #bbf7d0",
          borderRadius: 8,
          marginTop: 8,
          fontFamily: "'DM Sans', sans-serif",
        }}>
          <span style={{ fontSize: 13, color: "#166534" }}>📎 {f}</span>
          <button
            onClick={() => onRemove(i)}
            style={{
              background: "none", border: "none", cursor: "pointer",
              color: "#dc2626", fontSize: 16, padding: "0 4px",
            }}
          >
            ×
          </button>
        </div>
      ))}
    </div>
  );
}

function StepInstanta({ form, set }) {
  return (
    <div>
      <h3 style={sectionHeadingStyle}>1. Instanța judecătorească</h3>
      <InfoBox type="legal">
        Cererea se depune la judecătoria de la domiciliul/sediul pârâtului, conform art. 107 C.pr.civ.
        Aplicația poate sugera automat instanța competentă în funcție de adresa pârâtului.
      </InfoBox>
      <div style={gridStyle}>
        <Field label="Județul" required>
          <Select value={form.judet} onChange={(e) => set("judet", e.target.value)}>
            <option value="">Selectează județul...</option>
            {counties.map((c) => (
              <option key={c} value={c}>{c}</option>
            ))}
          </Select>
        </Field>
        <Field label="Judecătoria" required hint="Se completează automat în funcție de adresa pârâtului">
          <Select value={form.judecatorie} onChange={(e) => set("judecatorie", e.target.value)}>
            <option value="">Selectează judecătoria...</option>
            <option value="Judecătoria Sector 1">Judecătoria Sector 1</option>
            <option value="Judecătoria Sector 2">Judecătoria Sector 2</option>
            <option value="Judecătoria Sector 3">Judecătoria Sector 3</option>
            <option value="Judecătoria Sector 4">Judecătoria Sector 4</option>
          </Select>
        </Field>
      </div>
    </div>
  );
}

function StepReclamant({ form, set }) {
  return (
    <div>
      <h3 style={sectionHeadingStyle}>2. Identificarea reclamantului</h3>
      <InfoBox type="info">
        Datele reclamantului sunt precompletate din contul dumneavoastră. Verificați corectitudinea lor.
      </InfoBox>

      <div style={{ display: "flex", gap: 8, marginBottom: 20 }}>
        {["Persoană fizică", "Persoană juridică"].map((t) => (
          <button
            key={t}
            onClick={() => set("tipReclamant", t)}
            style={{
              flex: 1,
              padding: "10px 16px",
              border: form.tipReclamant === t ? "2px solid #2563eb" : "1.5px solid #d5dce6",
              borderRadius: 10,
              background: form.tipReclamant === t ? "#eff6ff" : "#fff",
              color: form.tipReclamant === t ? "#2563eb" : "#4b5563",
              fontWeight: form.tipReclamant === t ? 700 : 500,
              fontSize: 14,
              cursor: "pointer",
              fontFamily: "'DM Sans', sans-serif",
              transition: "all 0.2s",
            }}
          >
            {t === "Persoană fizică" ? "👤" : "🏢"} {t}
          </button>
        ))}
      </div>

      {form.tipReclamant === "Persoană fizică" ? (
        <div style={gridStyle}>
          <Field label="Nume și prenume" required>
            <Input value={form.numeReclamant} onChange={(e) => set("numeReclamant", e.target.value)} placeholder="ex: Popescu Ion" />
          </Field>
          <Field label="CNP" required hint="Codul numeric personal — 13 cifre">
            <Input value={form.cnpReclamant} onChange={(e) => set("cnpReclamant", e.target.value)} placeholder="1234567890123" maxLength={13} />
          </Field>
          <Field label="Domiciliu / Reședință" required wide>
            <Input value={form.adresaReclamant} onChange={(e) => set("adresaReclamant", e.target.value)} placeholder="Str., Nr., Bl., Sc., Et., Ap., Localitate, Județ" />
          </Field>
          <Field label="Email" required>
            <Input type="email" value={form.emailReclamant} onChange={(e) => set("emailReclamant", e.target.value)} placeholder="email@exemplu.ro" />
          </Field>
          <Field label="Telefon">
            <Input value={form.telReclamant} onChange={(e) => set("telReclamant", e.target.value)} placeholder="07xx xxx xxx" />
          </Field>
        </div>
      ) : (
        <div style={gridStyle}>
          <Field label="Denumirea societății" required>
            <Input value={form.numeReclamant} onChange={(e) => set("numeReclamant", e.target.value)} placeholder="SC Exemplu SRL" />
          </Field>
          <Field label="CUI / CIF" required>
            <Input value={form.cuiReclamant} onChange={(e) => set("cuiReclamant", e.target.value)} placeholder="RO12345678" />
          </Field>
          <Field label="Nr. Reg. Comerțului" required>
            <Input value={form.regComReclamant} onChange={(e) => set("regComReclamant", e.target.value)} placeholder="J40/1234/2020" />
          </Field>
          <Field label="Sediu social" required wide>
            <Input value={form.adresaReclamant} onChange={(e) => set("adresaReclamant", e.target.value)} placeholder="Str., Nr., Localitate, Județ" />
          </Field>
          <Field label="Reprezentant legal" required>
            <Input value={form.repLegal} onChange={(e) => set("repLegal", e.target.value)} placeholder="Nume și prenume" />
          </Field>
          <Field label="Email" required>
            <Input type="email" value={form.emailReclamant} onChange={(e) => set("emailReclamant", e.target.value)} placeholder="email@firma.ro" />
          </Field>
        </div>
      )}

      <div style={{ marginTop: 8 }}>
        <label style={{ display: "flex", alignItems: "center", gap: 8, cursor: "pointer", fontFamily: "'DM Sans', sans-serif", fontSize: 14, color: "#374151" }}>
          <input
            type="checkbox"
            checked={form.areReprezentant}
            onChange={(e) => set("areReprezentant", e.target.checked)}
            style={{ width: 18, height: 18, accentColor: "#2563eb" }}
          />
          Sunt reprezentat de un avocat
        </label>
      </div>

      {form.areReprezentant && (
        <div style={{ ...gridStyle, marginTop: 16, padding: 16, background: "#f8fafc", borderRadius: 12, border: "1px solid #e2e8f0" }}>
          <Field label="Nume avocat" required>
            <Input value={form.numeAvocat} onChange={(e) => set("numeAvocat", e.target.value)} placeholder="Av. Ionescu Maria" />
          </Field>
          <Field label="Barou / Nr. legitimație" required>
            <Input value={form.barouAvocat} onChange={(e) => set("barouAvocat", e.target.value)} placeholder="Baroul București / Nr. 1234" />
          </Field>
        </div>
      )}
    </div>
  );
}

function StepParat({ form, set }) {
  return (
    <div>
      <h3 style={sectionHeadingStyle}>3. Identificarea pârâtului</h3>
      <InfoBox type="info">
        Completați datele persoanei de la care doriți să recuperați suma. Instanța competentă va fi determinată automat.
      </InfoBox>

      <div style={{ display: "flex", gap: 8, marginBottom: 20 }}>
        {["Persoană fizică", "Persoană juridică"].map((t) => (
          <button
            key={t}
            onClick={() => set("tipParat", t)}
            style={{
              flex: 1,
              padding: "10px 16px",
              border: form.tipParat === t ? "2px solid #2563eb" : "1.5px solid #d5dce6",
              borderRadius: 10,
              background: form.tipParat === t ? "#eff6ff" : "#fff",
              color: form.tipParat === t ? "#2563eb" : "#4b5563",
              fontWeight: form.tipParat === t ? 700 : 500,
              fontSize: 14,
              cursor: "pointer",
              fontFamily: "'DM Sans', sans-serif",
              transition: "all 0.2s",
            }}
          >
            {t === "Persoană fizică" ? "👤" : "🏢"} {t}
          </button>
        ))}
      </div>

      {form.tipParat === "Persoană fizică" ? (
        <div style={gridStyle}>
          <Field label="Nume și prenume" required>
            <Input value={form.numeParat} onChange={(e) => set("numeParat", e.target.value)} placeholder="ex: Georgescu Ana" />
          </Field>
          <Field label="CNP" hint="Dacă este cunoscut">
            <Input value={form.cnpParat} onChange={(e) => set("cnpParat", e.target.value)} placeholder="Opțional" maxLength={13} />
          </Field>
          <Field label="Domiciliu / Reședință" required wide hint="Adresa la care se vor trimite comunicările">
            <Input value={form.adresaParat} onChange={(e) => set("adresaParat", e.target.value)} placeholder="Str., Nr., Bl., Sc., Et., Ap., Localitate, Județ" />
          </Field>
          <Field label="Email" hint="Dacă este cunoscut">
            <Input type="email" value={form.emailParat} onChange={(e) => set("emailParat", e.target.value)} placeholder="Opțional" />
          </Field>
          <Field label="Telefon" hint="Dacă este cunoscut">
            <Input value={form.telParat} onChange={(e) => set("telParat", e.target.value)} placeholder="Opțional" />
          </Field>
        </div>
      ) : (
        <div style={gridStyle}>
          <Field label="Denumirea societății" required>
            <Input value={form.numeParat} onChange={(e) => set("numeParat", e.target.value)} placeholder="SC Debitor SRL" />
          </Field>
          <Field label="CUI / CIF" required>
            <Input value={form.cuiParat} onChange={(e) => set("cuiParat", e.target.value)} placeholder="RO12345678" />
          </Field>
          <Field label="Nr. Reg. Comerțului">
            <Input value={form.regComParat} onChange={(e) => set("regComParat", e.target.value)} placeholder="J40/1234/2020" />
          </Field>
          <Field label="Sediu social" required wide>
            <Input value={form.adresaParat} onChange={(e) => set("adresaParat", e.target.value)} placeholder="Str., Nr., Localitate, Județ" />
          </Field>
          <Field label="Reprezentant legal" hint="Dacă este cunoscut">
            <Input value={form.repLegalParat} onChange={(e) => set("repLegalParat", e.target.value)} />
          </Field>
          <Field label="Email">
            <Input type="email" value={form.emailParat} onChange={(e) => set("emailParat", e.target.value)} placeholder="Opțional" />
          </Field>
        </div>
      )}
    </div>
  );
}

function StepCreanta({ form, set }) {
  const suma = parseFloat(form.sumaCreanta) || 0;
  const taxa = suma <= 2000 ? 50 : suma <= 10000 ? 200 : null;

  return (
    <div>
      <h3 style={sectionHeadingStyle}>4. Informații privind creanța</h3>

      <div style={gridStyle}>
        <Field label="Valoarea obligației principale" required hint="Doar suma principală, fără dobânzi sau cheltuieli accesorii. Max 10.000 lei.">
          <div style={{ position: "relative" }}>
            <Input
              type="number"
              value={form.sumaCreanta}
              onChange={(e) => set("sumaCreanta", e.target.value)}
              placeholder="0.00"
              style={{ paddingRight: 50 }}
              max={10000}
            />
            <span style={{
              position: "absolute", right: 14, top: "50%", transform: "translateY(-50%)",
              color: "#6b7280", fontSize: 14, fontWeight: 600, fontFamily: "'DM Sans', sans-serif",
            }}>
              LEI
            </span>
          </div>
        </Field>

        <Field label="Moneda">
          <Select value={form.moneda} onChange={(e) => set("moneda", e.target.value)}>
            <option value="RON">RON — Leu românesc</option>
            <option value="EUR">EUR — Euro</option>
          </Select>
        </Field>
      </div>

      {suma > 10000 && (
        <InfoBox type="warning">
          Suma depășește limita de 10.000 lei. Procedura cererii cu valoare redusă nu este aplicabilă. Cererea va fi judecată conform dreptului comun.
        </InfoBox>
      )}

      {suma > 0 && suma <= 10000 && (
        <div style={{
          background: "linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%)",
          border: "1px solid #bfdbfe",
          borderRadius: 12,
          padding: 16,
          marginBottom: 20,
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
        }}>
          <div>
            <p style={{ margin: 0, fontSize: 12, color: "#6b7280", fontFamily: "'DM Sans', sans-serif" }}>
              Taxă judiciară calculată automat (OUG 80/2013)
            </p>
            <p style={{ margin: "4px 0 0", fontSize: 24, fontWeight: 700, color: "#166534", fontFamily: "'DM Sans', sans-serif" }}>
              {taxa} LEI
            </p>
          </div>
          <div style={{
            background: "#fff",
            borderRadius: 8,
            padding: "8px 12px",
            fontSize: 12,
            color: "#4b5563",
            fontFamily: "'DM Sans', sans-serif",
            lineHeight: 1.4,
          }}>
            {suma <= 2000 ? "Cereri ≤ 2.000 lei" : "Cereri 2.001 – 10.000 lei"}
          </div>
        </div>
      )}

      <Field label="Descrierea obligației" required wide hint="Descrieți pe scurt ce anume datorează pârâtul și temeiul juridic (contract, factură, acord verbal etc.)">
        <TextArea
          value={form.descriereCreanta}
          onChange={(e) => set("descriereCreanta", e.target.value)}
          placeholder="Ex: În baza contractului de prestări servicii nr. 123/01.06.2025, pârâtul datorează suma de 5.000 lei, reprezentând contravaloarea serviciilor prestate conform facturii nr. 456/15.07.2025, scadentă la 30.07.2025 și neplătită până în prezent."
        />
      </Field>

      <div style={gridStyle}>
        <Field label="Data scadenței" required hint="Data la care trebuia achitată obligația">
          <Input type="date" value={form.dataScadenta} onChange={(e) => set("dataScadenta", e.target.value)} />
        </Field>
        <Field label="Temeiul juridic">
          <Select value={form.temeiJuridic} onChange={(e) => set("temeiJuridic", e.target.value)}>
            <option value="">Selectează...</option>
            <option value="contract">Contract</option>
            <option value="factura">Factură neachitată</option>
            <option value="imprumut">Împrumut</option>
            <option value="prestari">Prestări servicii</option>
            <option value="altul">Altul</option>
          </Select>
        </Field>
      </div>

      <div style={{ borderTop: "1px solid #e5e7eb", paddingTop: 20, marginTop: 12 }}>
        <h4 style={{ fontSize: 15, fontWeight: 700, color: "#1a2a3a", margin: "0 0 12px", fontFamily: "'DM Sans', sans-serif" }}>
          Dobânzi (opțional)
        </h4>
        <div style={{ display: "flex", gap: 8, marginBottom: 16 }}>
          {["Nu solicit dobânzi", "Dobândă contractuală", "Dobândă legală"].map((t) => (
            <button
              key={t}
              onClick={() => set("tipDobanda", t)}
              style={{
                padding: "8px 14px",
                border: form.tipDobanda === t ? "2px solid #2563eb" : "1.5px solid #d5dce6",
                borderRadius: 8,
                background: form.tipDobanda === t ? "#eff6ff" : "#fff",
                color: form.tipDobanda === t ? "#2563eb" : "#4b5563",
                fontWeight: form.tipDobanda === t ? 600 : 400,
                fontSize: 13,
                cursor: "pointer",
                fontFamily: "'DM Sans', sans-serif",
                transition: "all 0.15s",
              }}
            >
              {t}
            </button>
          ))}
        </div>
        {form.tipDobanda === "Dobândă contractuală" && (
          <div style={gridStyle}>
            <Field label="Rata dobânzii (%)" required>
              <Input type="number" value={form.rataDobanda} onChange={(e) => set("rataDobanda", e.target.value)} placeholder="ex: 5" />
            </Field>
            <Field label="Data de la care curge dobânda" required>
              <Input type="date" value={form.dataDobanda} onChange={(e) => set("dataDobanda", e.target.value)} />
            </Field>
          </div>
        )}
        {form.tipDobanda === "Dobândă legală" && (
          <Field label="Data de la care curge dobânda legală" required>
            <Input type="date" value={form.dataDobanda} onChange={(e) => set("dataDobanda", e.target.value)} />
          </Field>
        )}
      </div>

      <div style={{ marginTop: 16 }}>
        <label style={{ display: "flex", alignItems: "center", gap: 8, cursor: "pointer", fontFamily: "'DM Sans', sans-serif", fontSize: 14, color: "#374151" }}>
          <input
            type="checkbox"
            checked={form.cheltuieliJudecata}
            onChange={(e) => set("cheltuieliJudecata", e.target.checked)}
            style={{ width: 18, height: 18, accentColor: "#2563eb" }}
          />
          Solicit restituirea cheltuielilor de judecată
        </label>
      </div>
    </div>
  );
}

function StepProbe({ form, set }) {
  const demoFiles = form.fisiere || [];
  return (
    <div>
      <h3 style={sectionHeadingStyle}>5. Probe și documente</h3>
      <InfoBox type="legal">
        Conform art. 1.028 alin. (3) C.pr.civ., aveți obligația de a depune copii de pe înscrisurile de care
        înțelegeți să vă folosiți. Instanța poate încuviința și alte probe (martori, expertiză) dacă nu generează cheltuieli disproporționate.
      </InfoBox>

      <Field label="Descrieți probele" required wide hint={'Indicați fiecare document și ce anume dovedește. Ex: "Contract nr. 123 — dovedește existența obligației"'}>
        <TextArea
          value={form.descriereProbe}
          onChange={(e) => set("descriereProbe", e.target.value)}
          placeholder={"1. Contract de prestări servicii nr. 123/01.06.2025 — dovedește existența obligației\n2. Factură fiscală nr. 456/15.07.2025 — dovedește cuantumul creanței\n3. Corespondență email — dovedește punerea în întârziere"}
          style={{ minHeight: 120 }}
        />
      </Field>

      <Field label="Încarcă documente" required wide>
        <FileUploadZone
          files={demoFiles}
          onAdd={() => {
            set("fisiere", [...demoFiles, `Document_${demoFiles.length + 1}.pdf`]);
          }}
          onRemove={(i) => {
            set("fisiere", demoFiles.filter((_, idx) => idx !== i));
          }}
        />
      </Field>

      <div style={{ borderTop: "1px solid #e5e7eb", paddingTop: 20, marginTop: 8 }}>
        <h4 style={{ fontSize: 15, fontWeight: 700, color: "#1a2a3a", margin: "0 0 12px", fontFamily: "'DM Sans', sans-serif" }}>
          Martori (opțional)
        </h4>
        <label style={{ display: "flex", alignItems: "center", gap: 8, cursor: "pointer", fontFamily: "'DM Sans', sans-serif", fontSize: 14, color: "#374151", marginBottom: 12 }}>
          <input
            type="checkbox"
            checked={form.areMartori}
            onChange={(e) => set("areMartori", e.target.checked)}
            style={{ width: 18, height: 18, accentColor: "#2563eb" }}
          />
          Doresc să propun martori
        </label>
        {form.areMartori && (
          <div style={gridStyle}>
            <Field label="Nume martor">
              <Input placeholder="Nume și prenume" />
            </Field>
            <Field label="Adresa martorului">
              <Input placeholder="Adresă completă" />
            </Field>
          </div>
        )}
      </div>

      <div style={{ borderTop: "1px solid #e5e7eb", paddingTop: 20, marginTop: 16 }}>
        <h4 style={{ fontSize: 15, fontWeight: 700, color: "#1a2a3a", margin: "0 0 12px", fontFamily: "'DM Sans', sans-serif" }}>
          Dezbateri orale
        </h4>
        <InfoBox type="info">
          Procedura este în principiu scrisă. Puteți solicita dezbateri orale, dar instanța poate refuza dacă le consideră nenecesare.
        </InfoBox>
        <label style={{ display: "flex", alignItems: "center", gap: 8, cursor: "pointer", fontFamily: "'DM Sans', sans-serif", fontSize: 14, color: "#374151" }}>
          <input
            type="checkbox"
            checked={form.dezbateriOrale}
            onChange={(e) => set("dezbateriOrale", e.target.checked)}
            style={{ width: 18, height: 18, accentColor: "#2563eb" }}
          />
          Solicit organizarea dezbaterilor orale
        </label>
      </div>
    </div>
  );
}

function StepConfirmare({ form }) {
  const suma = parseFloat(form.sumaCreanta) || 0;
  const taxa = suma <= 2000 ? 50 : suma <= 10000 ? 200 : 0;

  const rows = [
    ["Instanța", form.judecatorie || "—"],
    ["Reclamant", `${form.numeReclamant || "—"} (${form.tipReclamant})`],
    ["Pârât", `${form.numeParat || "—"} (${form.tipParat})`],
    ["Suma pretinsă", suma > 0 ? `${suma.toLocaleString("ro-RO")} LEI` : "—"],
    ["Taxă judiciară", taxa > 0 ? `${taxa} LEI` : "—"],
    ["Dobânzi", form.tipDobanda || "Nu"],
    ["Documente atașate", `${(form.fisiere || []).length} fișier(e)`],
    ["Dezbateri orale", form.dezbateriOrale ? "Da, solicit" : "Nu"],
    ["Cheltuieli de judecată", form.cheltuieliJudecata ? "Da, solicit restituirea" : "Nu"],
  ];

  return (
    <div>
      <h3 style={sectionHeadingStyle}>6. Verificare și confirmare</h3>
      <InfoBox type="success">
        Verificați toate datele înainte de a trimite cererea. După trimitere, cererea va fi formatată automat conform Anexei 1 (OMJ 359/C/2013) și va fi disponibilă și în format PDF.
      </InfoBox>

      <div style={{
        borderRadius: 12,
        overflow: "hidden",
        border: "1px solid #e5e7eb",
        marginBottom: 20,
      }}>
        {rows.map(([label, val], i) => (
          <div key={label} style={{
            display: "flex",
            justifyContent: "space-between",
            padding: "12px 16px",
            background: i % 2 === 0 ? "#fafbfc" : "#fff",
            fontFamily: "'DM Sans', sans-serif",
            fontSize: 14,
          }}>
            <span style={{ color: "#6b7280", fontWeight: 500 }}>{label}</span>
            <span style={{ color: "#1a2a3a", fontWeight: 600, textAlign: "right", maxWidth: "60%" }}>{val}</span>
          </div>
        ))}
      </div>

      {form.descriereCreanta && (
        <div style={{ marginBottom: 20 }}>
          <p style={{ fontSize: 13, color: "#6b7280", fontWeight: 600, marginBottom: 6, fontFamily: "'DM Sans', sans-serif" }}>Descrierea obligației</p>
          <div style={{
            background: "#f8fafc",
            border: "1px solid #e5e7eb",
            borderRadius: 10,
            padding: 14,
            fontSize: 14,
            color: "#374151",
            lineHeight: 1.5,
            fontFamily: "'DM Sans', sans-serif",
          }}>
            {form.descriereCreanta}
          </div>
        </div>
      )}

      <div style={{
        background: "linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%)",
        borderRadius: 14,
        padding: 20,
        color: "#fff",
        marginBottom: 16,
      }}>
        <p style={{ margin: "0 0 4px", fontSize: 13, opacity: 0.8, fontFamily: "'DM Sans', sans-serif" }}>
          Total de plată
        </p>
        <div style={{ display: "flex", alignItems: "baseline", gap: 8 }}>
          <span style={{ fontSize: 32, fontWeight: 800, fontFamily: "'DM Sans', sans-serif" }}>
            {(taxa + 29.90).toFixed(2)} LEI
          </span>
        </div>
        <div style={{ display: "flex", justifyContent: "space-between", marginTop: 12, fontSize: 13, opacity: 0.85, fontFamily: "'DM Sans', sans-serif" }}>
          <span>Taxă judiciară: {taxa} LEI</span>
          <span>Comision platformă: 29.90 LEI</span>
        </div>
      </div>

      <label style={{ display: "flex", alignItems: "flex-start", gap: 8, cursor: "pointer", fontFamily: "'DM Sans', sans-serif", fontSize: 13, color: "#374151", lineHeight: 1.5 }}>
        <input
          type="checkbox"
          style={{ width: 18, height: 18, accentColor: "#2563eb", marginTop: 2, flexShrink: 0 }}
        />
        Confirm că datele sunt corecte și complete. Sunt de acord cu Termenii și Condițiile platformei și înțeleg că cererea va fi trimisă la instanța indicată.
      </label>
    </div>
  );
}

const sectionHeadingStyle = {
  fontSize: 18,
  fontWeight: 800,
  color: "#0f172a",
  margin: "0 0 16px",
  fontFamily: "'DM Sans', sans-serif",
  letterSpacing: "-0.02em",
};

const gridStyle = {
  display: "grid",
  gridTemplateColumns: "1fr 1fr",
  gap: "0 20px",
};

const initialForm = {
  judet: "", judecatorie: "",
  tipReclamant: "Persoană fizică",
  numeReclamant: "", cnpReclamant: "", adresaReclamant: "",
  emailReclamant: "", telReclamant: "", cuiReclamant: "",
  regComReclamant: "", repLegal: "",
  areReprezentant: false, numeAvocat: "", barouAvocat: "",
  tipParat: "Persoană fizică",
  numeParat: "", cnpParat: "", adresaParat: "",
  emailParat: "", telParat: "", cuiParat: "",
  regComParat: "", repLegalParat: "",
  sumaCreanta: "", moneda: "RON", descriereCreanta: "",
  dataScadenta: "", temeiJuridic: "",
  tipDobanda: "Nu solicit dobânzi", rataDobanda: "", dataDobanda: "",
  cheltuieliJudecata: false,
  descriereProbe: "", fisiere: [], areMartori: false,
  dezbateriOrale: false,
};

export default function FormularCerere() {
  const [step, setStep] = useState(1);
  const [form, setForm] = useState(initialForm);
  const [saved, setSaved] = useState(false);

  const set = (key, val) => {
    setForm((prev) => ({ ...prev, [key]: val }));
    setSaved(false);
  };

  const renderStep = () => {
    switch (step) {
      case 1: return <StepInstanta form={form} set={set} />;
      case 2: return <StepReclamant form={form} set={set} />;
      case 3: return <StepParat form={form} set={set} />;
      case 4: return <StepCreanta form={form} set={set} />;
      case 5: return <StepProbe form={form} set={set} />;
      case 6: return <StepConfirmare form={form} />;
      default: return null;
    }
  };

  return (
    <div style={{
      minHeight: "100vh",
      background: "linear-gradient(160deg, #f0f4f8 0%, #e8edf5 50%, #f5f3ff 100%)",
      fontFamily: "'DM Sans', sans-serif",
    }}>
      <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />

      {/* Header */}
      <div style={{
        background: "#fff",
        borderBottom: "1px solid #e5e7eb",
        padding: "14px 24px",
        display: "flex",
        alignItems: "center",
        justifyContent: "space-between",
        position: "sticky",
        top: 0,
        zIndex: 10,
      }}>
        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
          <div style={{
            width: 36, height: 36, borderRadius: 10,
            background: "linear-gradient(135deg, #1e3a5f, #2563eb)",
            display: "flex", alignItems: "center", justifyContent: "center",
            color: "#fff", fontWeight: 800, fontSize: 16,
          }}>
            ⚖️
          </div>
          <div>
            <h1 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: "#0f172a", letterSpacing: "-0.02em" }}>
              Cerere cu Valoare Redusă
            </h1>
            <p style={{ margin: 0, fontSize: 12, color: "#94a3b8" }}>
              Conform OMJ 359/C/2013 — Anexa 1
            </p>
          </div>
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          <button
            onClick={() => setSaved(true)}
            style={{
              padding: "8px 16px",
              border: "1.5px solid #d5dce6",
              borderRadius: 8,
              background: saved ? "#f0fdf4" : "#fff",
              color: saved ? "#166534" : "#4b5563",
              fontSize: 13,
              fontWeight: 600,
              cursor: "pointer",
              fontFamily: "'DM Sans', sans-serif",
              transition: "all 0.2s",
            }}
          >
            {saved ? "✓ Salvat" : "💾 Salvează draft"}
          </button>
        </div>
      </div>

      {/* Stepper */}
      <div style={{
        maxWidth: 760,
        margin: "0 auto",
        padding: "20px 24px 0",
      }}>
        <div style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          marginBottom: 8,
        }}>
          {STEPS.map((s, i) => (
            <div key={s.id} style={{ display: "flex", alignItems: "center", flex: i < STEPS.length - 1 ? 1 : "none" }}>
              <button
                onClick={() => setStep(s.id)}
                style={{
                  width: 40, height: 40,
                  borderRadius: "50%",
                  border: step === s.id ? "2px solid #2563eb" : s.id < step ? "2px solid #22c55e" : "1.5px solid #d5dce6",
                  background: s.id < step ? "#22c55e" : step === s.id ? "#2563eb" : "#fff",
                  color: s.id <= step ? "#fff" : "#94a3b8",
                  fontSize: 16,
                  cursor: "pointer",
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  transition: "all 0.2s",
                  flexShrink: 0,
                }}
              >
                {s.id < step ? "✓" : s.icon}
              </button>
              {i < STEPS.length - 1 && (
                <div style={{
                  flex: 1,
                  height: 2,
                  background: s.id < step ? "#22c55e" : "#e5e7eb",
                  margin: "0 6px",
                  borderRadius: 1,
                  transition: "background 0.3s",
                }} />
              )}
            </div>
          ))}
        </div>
        <div style={{
          display: "flex",
          justifyContent: "space-between",
          marginBottom: 20,
        }}>
          {STEPS.map((s) => (
            <span key={s.id} style={{
              fontSize: 11,
              color: step === s.id ? "#2563eb" : "#94a3b8",
              fontWeight: step === s.id ? 700 : 400,
              textAlign: "center",
              width: 64,
              fontFamily: "'DM Sans', sans-serif",
            }}>
              {s.label}
            </span>
          ))}
        </div>
      </div>

      {/* Content */}
      <div style={{
        maxWidth: 760,
        margin: "0 auto",
        padding: "0 24px 100px",
      }}>
        <div style={{
          background: "#fff",
          borderRadius: 16,
          padding: "28px 28px 24px",
          boxShadow: "0 1px 3px rgba(0,0,0,0.06), 0 8px 24px rgba(0,0,0,0.04)",
          border: "1px solid #e8ecf1",
        }}>
          {renderStep()}
        </div>

        {/* Navigation */}
        <div style={{
          display: "flex",
          justifyContent: "space-between",
          marginTop: 20,
        }}>
          <button
            onClick={() => setStep(Math.max(1, step - 1))}
            disabled={step === 1}
            style={{
              padding: "12px 24px",
              border: "1.5px solid #d5dce6",
              borderRadius: 10,
              background: "#fff",
              color: step === 1 ? "#cbd5e1" : "#374151",
              fontSize: 14,
              fontWeight: 600,
              cursor: step === 1 ? "not-allowed" : "pointer",
              fontFamily: "'DM Sans', sans-serif",
              transition: "all 0.2s",
            }}
          >
            ← Înapoi
          </button>
          <button
            onClick={() => {
              if (step < 6) setStep(step + 1);
            }}
            style={{
              padding: "12px 28px",
              border: "none",
              borderRadius: 10,
              background: step === 6
                ? "linear-gradient(135deg, #16a34a, #22c55e)"
                : "linear-gradient(135deg, #1e3a5f, #2563eb)",
              color: "#fff",
              fontSize: 14,
              fontWeight: 700,
              cursor: "pointer",
              fontFamily: "'DM Sans', sans-serif",
              boxShadow: "0 4px 12px rgba(37,99,235,0.3)",
              transition: "all 0.2s",
            }}
          >
            {step === 6 ? "💳 Plătește și trimite cererea" : "Continuă →"}
          </button>
        </div>
      </div>
    </div>
  );
}
