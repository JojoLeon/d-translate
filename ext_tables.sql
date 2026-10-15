CREATE TABLE tx_dtranslate_llm_config (
    uid int(11) unsigned NOT NULL auto_increment,
    title varchar(255) DEFAULT '' NOT NULL,
    type varchar(32) DEFAULT 'anthropic' NOT NULL,
    endpoint varchar(255) DEFAULT '' NOT NULL,
    api_key varchar(255) DEFAULT '' NOT NULL,
    chat_model varchar(128) DEFAULT '' NOT NULL,
    PRIMARY KEY (uid)
);

CREATE TABLE tx_dtranslate_profile (
    uid int(11) unsigned NOT NULL auto_increment,
    title varchar(255) DEFAULT '' NOT NULL,
    site_identifier varchar(512) DEFAULT '' NOT NULL,
    llm_config int(11) unsigned DEFAULT 0 NOT NULL,
    source_language varchar(64) DEFAULT '' NOT NULL,
    formality varchar(16) DEFAULT 'default' NOT NULL,
    style_hint text,
    translate_tables text,
    additional_fields text,
    glossary int(11) unsigned DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    KEY site (site_identifier)
);

CREATE TABLE tx_dtranslate_glossary (
    uid int(11) unsigned NOT NULL auto_increment,
    profile int(11) unsigned DEFAULT 0 NOT NULL,
    target_language varchar(16) DEFAULT '-1' NOT NULL,
    source_term varchar(255) DEFAULT '' NOT NULL,
    target_term varchar(255) DEFAULT '' NOT NULL,
    note varchar(255) DEFAULT '' NOT NULL,
    sorting int(11) DEFAULT 0 NOT NULL,
    PRIMARY KEY (uid),
    KEY profile (profile)
);

#
# Translation runs: one job per module submit, one item per record × language.
# Both the browser-driven run and the background worker consume the same items,
# which is what makes a run resumable and a big run survivable.
#
CREATE TABLE tx_dtranslate_job (
    uid int(11) unsigned NOT NULL auto_increment,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    page_id int(11) unsigned DEFAULT 0 NOT NULL,
    profile int(11) unsigned DEFAULT 0 NOT NULL,
    languages varchar(255) DEFAULT '' NOT NULL,
    publish_page smallint(5) unsigned DEFAULT 0 NOT NULL,
    skip_translated smallint(5) unsigned DEFAULT 0 NOT NULL,
    background smallint(5) unsigned DEFAULT 0 NOT NULL,
    state varchar(16) DEFAULT 'queued' NOT NULL,
    total int(11) unsigned DEFAULT 0 NOT NULL,
    done int(11) unsigned DEFAULT 0 NOT NULL,
    failed int(11) unsigned DEFAULT 0 NOT NULL,
    started int(11) unsigned DEFAULT 0 NOT NULL,
    finished int(11) unsigned DEFAULT 0 NOT NULL,
    heartbeat int(11) unsigned DEFAULT 0 NOT NULL,
    tokens_in int(11) unsigned DEFAULT 0 NOT NULL,
    tokens_out int(11) unsigned DEFAULT 0 NOT NULL,
    calls int(11) unsigned DEFAULT 0 NOT NULL,
    be_user int(11) unsigned DEFAULT 0 NOT NULL,
    message text,
    PRIMARY KEY (uid),
    KEY state (state),
    KEY page (page_id)
);

CREATE TABLE tx_dtranslate_job_item (
    uid int(11) unsigned NOT NULL auto_increment,
    job int(11) unsigned DEFAULT 0 NOT NULL,
    sorting int(11) unsigned DEFAULT 0 NOT NULL,
    table_name varchar(128) DEFAULT '' NOT NULL,
    record_uid int(11) unsigned DEFAULT 0 NOT NULL,
    language_id int(11) DEFAULT 0 NOT NULL,
    state varchar(16) DEFAULT 'pending' NOT NULL,
    attempts smallint(5) unsigned DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    message text,
    PRIMARY KEY (uid),
    KEY job_state (job,state,sorting)
);
