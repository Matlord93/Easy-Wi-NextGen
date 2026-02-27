package main

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"database/sql"
	"fmt"
	"math/rand"
	"net"
	"os"
	"regexp"
	"strings"
	"time"

	"easywi/agent/internal/jobs"

	mysql "github.com/go-sql-driver/mysql"
	_ "github.com/jackc/pgx/v5/stdlib"
)

const (
	dbErrNodeUnreachable = "db_node_unreachable"
	dbErrAuthFailed      = "db_auth_failed"
	dbErrTLSFailed       = "db_tls_failed"
	dbErrNameInvalid     = "db_name_invalid"
	dbErrActionFailed    = "db_action_failed"
)

var dbIdentifierRegex = regexp.MustCompile(`^[a-zA-Z][a-zA-Z0-9_]{2,62}$`)

func handleDatabaseCreate(job jobs.Job) (jobs.Result, func() error) {
	req, err := parseDatabaseRequest(job)
	if err != nil {
		return dbFailure(job.ID, dbErrNameInvalid, err.Error()), nil
	}

	db, cleanup, err := openDatabaseAdminConnection(req)
	if err != nil {
		return dbFailure(job.ID, mapDatabaseOpenError(err), sanitizeDBError(err)), nil
	}
	defer cleanup()

	password := generateStrongPassword(28)
	if req.Engine == "postgresql" {
		if err = postgresEnsureDatabaseAndUser(db, req, password); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
	} else {
		quotedDB := quoteIdentifier(req.Database)
		quotedUser := quoteUser(req.Username, req.AllowedHost)
		if err = execWithTimeout(db, 8*time.Second, "CREATE DATABASE IF NOT EXISTS "+quotedDB); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
		if err = execWithTimeout(db, 8*time.Second, "CREATE USER IF NOT EXISTS "+quotedUser+" IDENTIFIED BY ?", password); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
		grantSQL := fmt.Sprintf("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON %s.* TO %s", quotedDB, quotedUser)
		if err = execWithTimeout(db, 8*time.Second, grantSQL); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
		if err = execWithTimeout(db, 8*time.Second, "FLUSH PRIVILEGES"); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"database": req.Database, "username": req.Username, "allowed_host": req.AllowedHost, "one_time_credential": password}, Completed: time.Now().UTC()}, nil
}

func handleDatabasePasswordReset(job jobs.Job) (jobs.Result, func() error) {
	return handleDatabasePasswordRotate(job)
}

func handleDatabasePasswordRotate(job jobs.Job) (jobs.Result, func() error) {
	req, err := parseDatabaseRequest(job)
	if err != nil {
		return dbFailure(job.ID, dbErrNameInvalid, err.Error()), nil
	}
	db, cleanup, err := openDatabaseAdminConnection(req)
	if err != nil {
		return dbFailure(job.ID, mapDatabaseOpenError(err), sanitizeDBError(err)), nil
	}
	defer cleanup()

	password := generateStrongPassword(28)
	if req.Engine == "postgresql" {
		err = execWithTimeout(db, 8*time.Second, "ALTER ROLE "+quotePGIdentifier(req.Username)+" WITH LOGIN PASSWORD '"+escapeLiteral(password)+"'")
		if err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
	} else {
		quotedUser := quoteUser(req.Username, req.AllowedHost)
		err = execWithTimeout(db, 8*time.Second, "ALTER USER "+quotedUser+" IDENTIFIED BY ?", password)
		if err != nil {
			err = execWithTimeout(db, 8*time.Second, "SET PASSWORD FOR "+quotedUser+" = PASSWORD(?)", password)
			if err != nil {
				return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
			}
		}
		if err = execWithTimeout(db, 8*time.Second, "FLUSH PRIVILEGES"); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"username": req.Username, "allowed_host": req.AllowedHost, "one_time_credential": password}, Completed: time.Now().UTC()}, nil
}

func handleDatabaseUserCreate(job jobs.Job) (jobs.Result, func() error) {
	return dbFailure(job.ID, dbErrActionFailed, "unsupported job"), nil
}
func handleDatabaseGrantApply(job jobs.Job) (jobs.Result, func() error) {
	return dbFailure(job.ID, dbErrActionFailed, "unsupported job"), nil
}

func handleDatabaseDelete(job jobs.Job) (jobs.Result, func() error) {
	req, err := parseDatabaseRequest(job)
	if err != nil {
		return dbFailure(job.ID, dbErrNameInvalid, err.Error()), nil
	}
	db, cleanup, err := openDatabaseAdminConnection(req)
	if err != nil {
		return dbFailure(job.ID, mapDatabaseOpenError(err), sanitizeDBError(err)), nil
	}
	defer cleanup()
	if req.Engine == "postgresql" {
		_ = execWithTimeout(db, 8*time.Second, "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = $1", req.Database)
		if err = execWithTimeout(db, 8*time.Second, "DROP DATABASE IF EXISTS "+quotePGIdentifier(req.Database)); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
		if err = execWithTimeout(db, 8*time.Second, "DROP ROLE IF EXISTS "+quotePGIdentifier(req.Username)); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
	} else {
		if err = execWithTimeout(db, 8*time.Second, "DROP DATABASE IF EXISTS "+quoteIdentifier(req.Database)); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
		if err = execWithTimeout(db, 8*time.Second, "DROP USER IF EXISTS "+quoteUser(req.Username, req.AllowedHost)); err != nil {
			return dbFailure(job.ID, dbErrActionFailed, sanitizeDBError(err)), nil
		}
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"database": req.Database, "username": req.Username, "status": "deleted"}, Completed: time.Now().UTC()}, nil
}

type databaseRequest struct {
	Engine, Host, Port, Database, Username, AllowedHost, AdminUser, AdminSecret, TLSMode, CACert string
}

func parseDatabaseRequest(job jobs.Job) (databaseRequest, error) {
	req := databaseRequest{Engine: strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "engine"))), Host: strings.TrimSpace(payloadValue(job.Payload, "host")), Port: strings.TrimSpace(payloadValue(job.Payload, "port")), Database: strings.TrimSpace(payloadValue(job.Payload, "database", "name")), Username: strings.TrimSpace(payloadValue(job.Payload, "username", "user")), AllowedHost: strings.TrimSpace(payloadValue(job.Payload, "allowed_hosts", "allowed_host")), AdminUser: strings.TrimSpace(payloadValue(job.Payload, "admin_user")), AdminSecret: payloadValue(job.Payload, "admin_secret"), TLSMode: strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "tls_mode"))), CACert: payloadValue(job.Payload, "ca_cert")}
	if req.AllowedHost == "" {
		req.AllowedHost = "%"
	}
	if req.AdminUser == "" {
		req.AdminUser = strings.TrimSpace(os.Getenv("DB_ADMIN_USER"))
	}
	if req.AdminSecret == "" {
		req.AdminSecret = strings.TrimSpace(os.Getenv("DB_ADMIN_SECRET"))
	}
	if req.TLSMode == "" {
		req.TLSMode = "off"
	}
	if req.Engine == "postgres" {
		req.Engine = "postgresql"
	}
	if req.Engine != "mysql" && req.Engine != "mariadb" && req.Engine != "postgresql" {
		return req, fmt.Errorf("unsupported engine")
	}
	if req.Host == "" || req.Port == "" || req.AdminUser == "" || req.AdminSecret == "" {
		return req, fmt.Errorf("missing db connection metadata")
	}
	if !dbIdentifierRegex.MatchString(req.Database) || !dbIdentifierRegex.MatchString(req.Username) {
		return req, fmt.Errorf("invalid database or username")
	}
	return req, nil
}

func openDatabaseAdminConnection(req databaseRequest) (*sql.DB, func(), error) {
	if req.Engine == "postgresql" {
		return openPostgresAdminConnection(req)
	}
	tlsName, cleanup, err := registerTLSConfig(req)
	if err != nil {
		return nil, func() {}, err
	}
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/information_schema?parseTime=true&timeout=5s&readTimeout=8s&writeTimeout=8s", req.AdminUser, req.AdminSecret, req.Host, req.Port)
	if tlsName != "" {
		dsn += "&tls=" + tlsName
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		cleanup()
		return nil, func() {}, err
	}
	return pingedDB(db, cleanup)
}

func openPostgresAdminConnection(req databaseRequest) (*sql.DB, func(), error) {
	sslMode := map[string]string{"off": "disable", "required": "require", "verify_ca": "verify-ca", "verify_full": "verify-full"}[req.TLSMode]
	if sslMode == "" {
		return nil, func() {}, fmt.Errorf("invalid tls mode")
	}
	dsn := fmt.Sprintf("host=%s port=%s user=%s password=%s dbname=postgres connect_timeout=5 sslmode=%s", req.Host, req.Port, req.AdminUser, req.AdminSecret, sslMode)
	db, err := sql.Open("pgx", dsn)
	if err != nil {
		return nil, func() {}, err
	}
	return pingedDB(db, func() {})
}

func pingedDB(db *sql.DB, cleanup func()) (*sql.DB, func(), error) {
	db.SetConnMaxLifetime(30 * time.Second)
	db.SetMaxOpenConns(2)
	db.SetMaxIdleConns(1)
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	err := db.PingContext(ctx)
	cancel()
	if err != nil {
		_ = db.Close()
		cleanup()
		return nil, func() {}, err
	}
	return db, func() { _ = db.Close(); cleanup() }, nil
}

func postgresEnsureDatabaseAndUser(db *sql.DB, req databaseRequest, password string) error {
	var exists int
	err := db.QueryRow("SELECT 1 FROM pg_roles WHERE rolname = $1", req.Username).Scan(&exists)
	if err == sql.ErrNoRows {
		if err = execWithTimeout(db, 8*time.Second, "CREATE ROLE "+quotePGIdentifier(req.Username)+" LOGIN PASSWORD '"+escapeLiteral(password)+"'"); err != nil {
			return err
		}
	} else if err == nil {
		if err = execWithTimeout(db, 8*time.Second, "ALTER ROLE "+quotePGIdentifier(req.Username)+" WITH LOGIN PASSWORD '"+escapeLiteral(password)+"'"); err != nil {
			return err
		}
	} else {
		return err
	}
	err = db.QueryRow("SELECT 1 FROM pg_database WHERE datname = $1", req.Database).Scan(&exists)
	if err == sql.ErrNoRows {
		if err = execWithTimeout(db, 8*time.Second, "CREATE DATABASE "+quotePGIdentifier(req.Database)+" OWNER "+quotePGIdentifier(req.Username)); err != nil {
			return err
		}
	} else if err != nil {
		return err
	}
	if err = execWithTimeout(db, 8*time.Second, "GRANT ALL PRIVILEGES ON DATABASE "+quotePGIdentifier(req.Database)+" TO "+quotePGIdentifier(req.Username)); err != nil {
		return err
	}
	return nil
}

func registerTLSConfig(req databaseRequest) (string, func(), error) {
	if req.TLSMode == "off" {
		return "", func() {}, nil
	}
	conf := &tls.Config{MinVersion: tls.VersionTLS12}
	switch req.TLSMode {
	case "required":
		conf.InsecureSkipVerify = true
	case "verify_ca", "verify_full":
		pool := x509.NewCertPool()
		if strings.TrimSpace(req.CACert) == "" || !pool.AppendCertsFromPEM([]byte(req.CACert)) {
			return "", func() {}, fmt.Errorf("tls ca cert invalid")
		}
		conf.RootCAs = pool
		if req.TLSMode == "verify_ca" {
			conf.InsecureSkipVerify = true
		}
		if req.TLSMode == "verify_full" {
			conf.ServerName = req.Host
		}
	default:
		return "", func() {}, fmt.Errorf("invalid tls mode")
	}
	name := fmt.Sprintf("dbtls_%d", time.Now().UnixNano())
	if err := mysql.RegisterTLSConfig(name, conf); err != nil {
		return "", func() {}, err
	}
	return name, func() { mysql.DeregisterTLSConfig(name) }, nil
}

func mapDatabaseOpenError(err error) string {
	message := strings.ToLower(err.Error())
	if strings.Contains(message, "access denied") || strings.Contains(message, "authentication") || strings.Contains(message, "password authentication failed") {
		return dbErrAuthFailed
	}
	if strings.Contains(message, "tls") || strings.Contains(message, "ssl") || strings.Contains(message, "certificate") || strings.Contains(message, "x509") {
		return dbErrTLSFailed
	}
	if isNetErr(err) {
		return dbErrNodeUnreachable
	}
	return dbErrActionFailed
}
func isNetErr(err error) bool {
	if err == nil {
		return false
	}
	if netErr, ok := err.(net.Error); ok && netErr != nil {
		return true
	}
	message := strings.ToLower(err.Error())
	return strings.Contains(message, "timeout") || strings.Contains(message, "connection refused") || strings.Contains(message, "no such host")
}
func sanitizeDBError(err error) string {
	msg := strings.ReplaceAll(strings.TrimSpace(err.Error()), "\n", " ")
	if len(msg) > 240 {
		msg = msg[:240]
	}
	return msg
}
func quoteIdentifier(value string) string { return "`" + strings.ReplaceAll(value, "`", "``") + "`" }
func quoteUser(username, host string) string {
	return fmt.Sprintf("'%s'@'%s'", strings.ReplaceAll(username, "'", "''"), strings.ReplaceAll(host, "'", "''"))
}
func quotePGIdentifier(value string) string { return `"` + strings.ReplaceAll(value, `"`, `""`) + `"` }
func escapeLiteral(value string) string     { return strings.ReplaceAll(value, "'", "''") }
func generateStrongPassword(length int) string {
	const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}"
	if length < 16 {
		length = 16
	}
	out := make([]byte, length)
	for i := range out {
		out[i] = chars[rand.Intn(len(chars))]
	}
	return string(out)
}
func dbFailure(jobID, errorCode, message string) jobs.Result {
	return jobs.Result{JobID: jobID, Status: "failed", Output: map[string]string{"error_code": errorCode, "error_message": message}, Completed: time.Now().UTC()}
}
func execWithTimeout(db *sql.DB, timeout time.Duration, query string, args ...any) error {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	_, err := db.ExecContext(ctx, query, args...)
	return err
}
