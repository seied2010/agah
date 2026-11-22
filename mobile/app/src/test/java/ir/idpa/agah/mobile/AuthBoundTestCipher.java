package ir.idpa.agah.mobile;

import java.security.*;
import java.security.spec.AlgorithmParameterSpec;
import javax.crypto.*;

/** Models Android Keystore's cached pre-auth AAD error; no hardware authentication is simulated. */
public final class AuthBoundTestCipher extends Cipher {
    private final Gate gate;
    public AuthBoundTestCipher() { this(new Gate()); }
    private AuthBoundTestCipher(Gate gate) {
        super(gate, new Provider("AuthBoundTest", 1.0, "Test-only per-operation authorization") {}, "AES/GCM/NoPadding");
        this.gate = gate;
    }
    public void authorize() { gate.authorized = true; }
    public boolean hadPrematureAad() { return gate.prematureAad; }
    private static final class Gate extends CipherSpi {
        private final Cipher delegate;
        private boolean authorized;
        private boolean prematureAad;
        Gate() {
            try { delegate = Cipher.getInstance("AES/GCM/NoPadding", "SunJCE"); }
            catch (GeneralSecurityException e) { throw new AssertionError(e); }
        }
        @Override protected void engineSetMode(String mode) {}
        @Override protected void engineSetPadding(String padding) {}
        @Override protected int engineGetBlockSize() { return delegate.getBlockSize(); }
        @Override protected int engineGetOutputSize(int size) { return delegate.getOutputSize(size); }
        @Override protected byte[] engineGetIV() { return delegate.getIV(); }
        @Override protected AlgorithmParameters engineGetParameters() { return delegate.getParameters(); }
        @Override protected void engineInit(int op, Key key, SecureRandom random) throws InvalidKeyException { delegate.init(op, key, random); }
        @Override protected void engineInit(int op, Key key, AlgorithmParameterSpec spec, SecureRandom random) throws InvalidKeyException, InvalidAlgorithmParameterException { delegate.init(op, key, spec, random); }
        @Override protected void engineInit(int op, Key key, AlgorithmParameters params, SecureRandom random) throws InvalidKeyException, InvalidAlgorithmParameterException { delegate.init(op, key, params, random); }
        @Override protected byte[] engineUpdate(byte[] input, int offset, int size) { return delegate.update(input, offset, size); }
        @Override protected int engineUpdate(byte[] input, int offset, int size, byte[] output, int outOffset) throws ShortBufferException { return delegate.update(input, offset, size, output, outOffset); }
        @Override protected void engineUpdateAAD(byte[] input, int offset, int size) {
            // Android's CipherSpi caches this failure instead of throwing from updateAAD.
            if (!authorized) { prematureAad = true; return; }
            delegate.updateAAD(input, offset, size);
        }
        private void checkAuthorization() throws IllegalBlockSizeException {
            if (prematureAad || !authorized) throw new IllegalBlockSizeException("Test-only cached authorization failure");
        }
        @Override protected byte[] engineDoFinal(byte[] input, int offset, int size) throws IllegalBlockSizeException, BadPaddingException {
            checkAuthorization(); return delegate.doFinal(input, offset, size);
        }
        @Override protected int engineDoFinal(byte[] input, int offset, int size, byte[] output, int outOffset) throws ShortBufferException, IllegalBlockSizeException, BadPaddingException {
            checkAuthorization(); return delegate.doFinal(input, offset, size, output, outOffset);
        }
    }
}
