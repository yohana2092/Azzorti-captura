CREATE TABLE capt (
    id SERIAL,
    comp VARCHAR(50) NOT NULL,
    cana VARCHAR(20) NOT NULL,
    camp VARCHAR(20) NOT NULL,
    cate VARCHAR(50) NOT NULL,
    nive_prec VARCHAR(20) NOT NULL,
    dscr VARCHAR(255),
    silu VARCHAR(50),
    tall VARCHAR(20),
    tela1 VARCHAR(255),
    tela2 VARCHAR(255),
    mang VARCHAR(50),
    colo VARCHAR(50),
    deta VARCHAR(255),
    cara VARCHAR(255),
    prec FLOAT NOT NULL,
    sku_comp VARCHAR(50),
    sku_conf VARCHAR(50),
    foto_arch VARCHAR(255),
    cata_id INTEGER,
    fcre VARCHAR(30) NOT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE azzo_prod (
    sku VARCHAR(20) NOT NULL,
    cate VARCHAR(50) NOT NULL,
    dscr VARCHAR(255),
    colo VARCHAR(50),
    tela VARCHAR(255),
    silu VARCHAR(50),
    mang VARCHAR(50),
    prec FLOAT NOT NULL,
    camp VARCHAR(20) NOT NULL,
    pagi_cata INTEGER,
    foto_arch VARCHAR(255),
    PRIMARY KEY (sku)
);

CREATE TABLE poli_prec (
    id SERIAL,
    cate VARCHAR(50) NOT NULL,
    comp VARCHAR(50) NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    umbr_pct FLOAT,
    PRIMARY KEY (id),
    UNIQUE (cate, comp)
);

CREATE TABLE capt_conf (
    clav VARCHAR(50) NOT NULL,
    valo VARCHAR(255) NOT NULL,
    fact VARCHAR(30) NOT NULL,
    PRIMARY KEY (clav)
);

CREATE TABLE cata_comp (
    id SERIAL,
    comp VARCHAR(50) NOT NULL,
    camp VARCHAR(20) NOT NULL,
    arch VARCHAR(255) NOT NULL,
    fsub VARCHAR(30) NOT NULL,
    PRIMARY KEY (id)
);

CREATE TABLE prod_estr (
    id SERIAL,
    comp VARCHAR(50) NOT NULL,
    cate VARCHAR(50),
    desc_comp VARCHAR(255) NOT NULL,
    modo VARCHAR(20) NOT NULL,
    azzo_refe VARCHAR(255),
    prec_comp FLOAT,
    prec_azzo FLOAT,
    foto_comp VARCHAR(255),
    foto_azzo VARCHAR(255),
    camp VARCHAR(20) NOT NULL,
    camp_azzo VARCHAR(20),
    delt_arch FLOAT,
    fact VARCHAR(30) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE (comp, desc_comp, camp)
);

CREATE TABLE ofer_refe (
    id SERIAL,
    comp VARCHAR(50) NOT NULL,
    nomb_ofer VARCHAR(255) NOT NULL,
    foto VARCHAR(255),
    fact VARCHAR(30) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE (comp, nomb_ofer)
);

CREATE TABLE cata_ofer (
    id SERIAL,
    cata_id INTEGER NOT NULL,
    pagi INTEGER NOT NULL,
    prod_codi VARCHAR(20),
    ofer_dete VARCHAR(255) NOT NULL,
    scor FLOAT,
    txt_ocr LVARCHAR(2000),
    fcre VARCHAR(30) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE (cata_id, pagi, ofer_dete, prod_codi),
    FOREIGN KEY (cata_id) REFERENCES cata_comp(id)
);

CREATE TABLE cata_prod (
    id SERIAL,
    cata_id INTEGER NOT NULL,
    pagi INTEGER NOT NULL,
    prod_codi VARCHAR(20) NOT NULL,
    txt_cerc LVARCHAR(500),
    prec FLOAT,
    secc VARCHAR(255),
    fcre VARCHAR(30) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE (cata_id, pagi, prod_codi),
    FOREIGN KEY (cata_id) REFERENCES cata_comp(id)
);
