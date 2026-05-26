package main

import (
	"database/sql"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"

	"github.com/DATA-DOG/go-sqlmock"
)

func TestParseDatabaseRequestAcceptsPostgresAlias(t *testing.T) {
	req, err := parseDatabaseRequest(jobs.Job{Payload: map[string]any{
		"engine":       "postgres",
		"host":         "127.0.0.1",
		"port":         "5432",
		"database":     "customerdb",
		"username":     "customerusr",
		"admin_user":   "svc_admin",
		"admin_secret": "secret",
	}})
	if err != nil {
		t.Fatalf("unexpected err: %v", err)
	}
	if req.Engine != "postgresql" {
		t.Fatalf("expected normalized engine postgresql, got %s", req.Engine)
	}
}

func TestParseDatabaseRequestRejectsInvalidNamesAndHost(t *testing.T) {
	base := map[string]any{
		"engine":       "mariadb",
		"host":         "127.0.0.1",
		"port":         "3306",
		"database":     "customerdb",
		"username":     "customerusr",
		"admin_user":   "svc_admin",
		"admin_secret": "secret",
	}

	invalidDB := mapsClone(base)
	invalidDB["database"] = "bad.name"
	if _, err := parseDatabaseRequest(jobs.Job{Payload: invalidDB}); err == nil {
		t.Fatal("expected invalid database name error")
	}

	invalidUser := mapsClone(base)
	invalidUser["username"] = "bad-user"
	if _, err := parseDatabaseRequest(jobs.Job{Payload: invalidUser}); err == nil {
		t.Fatal("expected invalid username error")
	}

	invalidHost := mapsClone(base)
	invalidHost["allowed_host"] = "%'; DROP USER root; --"
	if _, err := parseDatabaseRequest(jobs.Job{Payload: invalidHost}); err == nil {
		t.Fatal("expected invalid allowed host error")
	}
}

func TestMySQLDDLStatementsDoNotUsePlaceholders(t *testing.T) {
	quotedDB := quoteIdentifier("u2_test")
	quotedUser := quoteUser("u2_test", "%")
	password := "s3cr'et\\pw"

	statements := []string{
		"CREATE DATABASE IF NOT EXISTS " + quotedDB,
		buildMySQLCreateUserSQL(quotedUser, password),
		buildMySQLGrantSQL(quotedDB, quotedUser),
		buildMySQLAlterUserSQL(quotedUser, password),
		"DROP USER IF EXISTS " + quotedUser,
		"DROP DATABASE IF EXISTS " + quotedDB,
		"FLUSH PRIVILEGES",
	}
	for _, stmt := range statements {
		if strings.Contains(stmt, "?") {
			t.Fatalf("statement must not contain placeholders: %s", stmt)
		}
	}
}

func TestQuoteStringLiteralEscapesSpecialChars(t *testing.T) {
	got := quoteStringLiteral("pa'ss\\word")
	if got != "'pa''ss\\\\word'" {
		t.Fatalf("unexpected literal quoting: %s", got)
	}
}

func TestSanitizeDBErrorDoesNotLeakPassword(t *testing.T) {
	password := "MyS3cret!"
	err := sanitizeDBError(dbTestErr("db failed for password=" + password))
	if strings.Contains(err, password) {
		t.Fatalf("sanitized error leaked password: %s", err)
	}
}

func mapsClone(in map[string]any) map[string]any {
	out := make(map[string]any, len(in))
	for k, v := range in {
		out[k] = v
	}
	return out
}

type dbFixedErr string

func (e dbFixedErr) Error() string { return string(e) }

func dbTestErr(msg string) error { return dbFixedErr(msg) }

func TestPostgresEnsureDatabaseAndUserAppliesRoleCreateAndGrant(t *testing.T) {
	db, mock, err := sqlmock.New()
	if err != nil {
		t.Fatalf("sqlmock: %v", err)
	}
	t.Cleanup(func() {
		if err := mock.ExpectationsWereMet(); err != nil {
			t.Fatalf("expectations: %v", err)
		}
	})

	dbScoped, scopedMock, err := sqlmock.New()
	if err != nil {
		t.Fatalf("sqlmock scoped: %v", err)
	}
	t.Cleanup(func() {
		if err := scopedMock.ExpectationsWereMet(); err != nil {
			t.Fatalf("scoped expectations: %v", err)
		}
	})

	oldScoped := openPostgresScopedConnectionFn
	openPostgresScopedConnectionFn = func(databaseRequest, string) (*sql.DB, func(), error) {
		return dbScoped, func() {}, nil
	}
	t.Cleanup(func() {
		openPostgresScopedConnectionFn = oldScoped
	})

	req := databaseRequest{Database: "custdb", Username: "custusr"}
	mock.ExpectQuery("SELECT 1 FROM pg_roles").WithArgs("custusr").WillReturnRows(sqlmock.NewRows([]string{"?"}))
	mock.ExpectExec("CREATE ROLE").WithArgs("newSecret").WillReturnResult(sqlmock.NewResult(0, 1))
	mock.ExpectQuery("SELECT 1 FROM pg_database").WithArgs("custdb").WillReturnRows(sqlmock.NewRows([]string{"?"}))
	mock.ExpectExec("CREATE DATABASE").WillReturnResult(sqlmock.NewResult(0, 1))
	mock.ExpectExec("GRANT ALL PRIVILEGES ON DATABASE").WillReturnResult(sqlmock.NewResult(0, 1))

	scopedMock.ExpectExec("GRANT USAGE, CREATE ON SCHEMA public").WillReturnResult(sqlmock.NewResult(0, 1))
	scopedMock.ExpectExec("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT").WillReturnResult(sqlmock.NewResult(0, 1))
	scopedMock.ExpectExec("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES").WillReturnResult(sqlmock.NewResult(0, 1))

	if err := postgresEnsureDatabaseAndUser(db, req, "newSecret"); err != nil {
		t.Fatalf("ensure failed: %v", err)
	}
}
